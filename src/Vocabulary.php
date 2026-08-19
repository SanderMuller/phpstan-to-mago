<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Node\InClassNode;

/**
 * The tables that say what a rule may be made of.
 *
 * Each maps a PHPStan construct to the Mago one that answers it. A construct absent from these
 * tables is refused rather than approximated, which is what keeps a generated rule trustworthy.
 */
final class Vocabulary
{
    /**
     * @var array<class-string, array{trait: string, method: string, node: string|null, kind: string, adapter?: string, extra?: string, classFrom?: string, classOnly?: bool, each?: string}>
     */
    public const array HOOKS = [
        MethodCall::class => ['trait' => 'MethodCallHook', 'method' => 'after_method_call', 'node' => 'MethodCall', 'kind' => 'MethodCall'],
        FuncCall::class => ['trait' => 'FunctionCallHook', 'method' => 'after_function_call', 'node' => 'FunctionCall', 'kind' => 'FunctionCall'],
        StaticCall::class => ['trait' => 'StaticMethodCallHook', 'method' => 'after_static_method_call', 'node' => 'StaticMethodCall', 'kind' => 'StaticMethodCall'],
        ClassConstFetch::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'adapter' => 'as_class_constant_access', 'kind' => 'ClassConstantAccess'],
        Assign::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'adapter' => 'as_assignment', 'kind' => 'Assignment'],
        Class_::class => ['trait' => 'ClassDeclarationHook', 'method' => 'on_enter_class', 'node' => 'Class', 'kind' => 'Class', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
        // Cross-file rules: PHPStan hands the collected data to a rule registered for this virtual node
        // once every file has been analysed, which is what the whole-run hook is for.
        CollectedDataNode::class => ['trait' => 'AnalysisHook', 'method' => 'after_analysis', 'node' => null, 'kind' => 'CollectedData'],
        // PHPStan's virtual per-class-like node. Mapped to the *class* declaration hook only, so a rule
        // that does not narrow to `Class_` is refused rather than silently missing interfaces and traits.
        InClassNode::class => ['trait' => 'ClassDeclarationHook', 'method' => 'on_enter_class', 'node' => 'Class', 'kind' => 'Class', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classOnly' => true, 'classFrom' => 'metadata'],
        ClassMethod::class => ['trait' => 'ClassLikeMemberHook', 'method' => 'on_method', 'node' => 'Method', 'kind' => 'Method', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
        New_::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'adapter' => 'as_instantiation', 'kind' => 'Instantiation'],
        Property::class => ['trait' => 'ClassLikeMemberHook', 'method' => 'on_property', 'node' => 'Property', 'kind' => 'Property', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
        ClassConst::class => ['trait' => 'ClassLikeMemberHook', 'method' => 'on_class_like_constant', 'node' => 'ClassLikeConstant', 'kind' => 'ClassLikeConstant', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
        // The *statement*, so one finding per declaration however many items it declares.
        Const_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'adapter' => 'as_global_constant', 'kind' => 'Constant'],
    ];

    /**
     * Where a PHPStan node's property lives on the Mago node, per node kind.
     *
     * [rust expression, descriptor kind]. `name-selector` and `name-expr` differ because a method
     * name is a member selector while a function name is an arbitrary expression — the same PHPStan
     * source (`$node->name`) compiles to different Rust.
     */
    /**
     * @var array<string, array<string, array{0: string, 1: string, 2?: string}>>
     */
    public const array FIELDS = [
        'MethodCall' => [
            'var' => ['node.object', 'expr', 'Support::nthExpression($context, $node, 0)'],
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, $node)'],
        ],
        'FunctionCall' => [
            'name' => ['node.function', 'name-expr', 'Support::nthExpression($context, $node, 0)'],
        ],
        'StaticMethodCall' => [
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, $node)'],
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, $node)'],
        ],
        'Assignment' => [
            // Both sides are an `Expression` child, told apart only by position.
            'var' => ['node.lhs', 'expr', 'Support::nthExpression($context, $node, 0)'],
            'expr' => ['node.rhs', 'expr', 'Support::nthExpression($context, $node, 1)'],
        ],
        'Class' => [
            'extends' => ['node', 'extends'],
        ],
        'ClassConstantAccess' => [
            // Rust reads the field; the PHP SDK's Node has no fields, so the class part is found by
            // walking children. A class-constant access has an Identifier for the class and a
            // ClassLikeConstantSelector for the constant, so the first Identifier is the class.
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, $node)'],
        ],
        'ClassLikeConstant' => [
            'consts' => ['node.items', 'const-items', 'Support::constantItems($context, $node)'],
        ],
        'Property' => [
            'type' => ['support::property_hint(node)', 'hint-option', 'Support::propertyHint($context, $node)'],
        ],
        'Method' => [
            'name' => ['&node.name', 'local-name'],
        ],
        'Instantiation' => [
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, $node)'],
        ],
    ];

    /**
     * What a `foreach` over a given descriptor kind yields, and how to iterate it.
     *
     * The loop variable's kind is what lets the body translate: iterating a constant declaration's items
     * gives items whose `->name` is an identifier, not an arbitrary expression.
     */
    /** Properties reachable from a local of a given descriptor kind. */
    /**
     * @var array<string, array<string, array{0: string, 1: string, 2?: string}>>
     */
    public const array KIND_FIELDS = [
        'property' => [
            'type' => ['support::property_hint({base})', 'hint-option', 'Support::propertyHint($context, {base})'],
        ],
        // An argument still wrapped, so how it was *written* can be asked of it. php-parser puts all three on
        // one `Arg`: `->value` is the expression, `->name` is set when the call names the parameter, and
        // `->unpack` is set when the argument is spread. Mago splits the first two across node kinds and spells
        // the third only in the text, so each field stays the argument itself and the predicate does the work.
        'argument' => [
            'value' => [self::PHP_ONLY, 'expr', 'Support::argumentValue({base})'],
            'name' => [self::PHP_ONLY, 'argument-name', '{base}'],
            'unpack' => [self::PHP_ONLY, 'argument-unpack', '{base}'],
        ],
    ];

    /**
     * Stands in for a Rust expression that does not exist, on an entry only the PHP target reads.
     *
     * A comment rather than a plausible identifier on purpose: if one reaches emitted Rust, the generated
     * crate fails to compile instead of quietly analysing the wrong thing.
     */
    public const string PHP_ONLY = '/* PHP target only */';

    /**
     * @var array<string, array{iter: string, item: string, phpIter?: string}>
     */
    public const array ITERABLES = [
        // A configured list of strings, carried by the generated plugin's constructor. An iterable in both
        // targets, so the emptiness test and a `foreach` over it need no special case elsewhere.
        'config-list' => ['iter' => '{rust}.iter()', 'item' => 'config-bytes', 'phpIter' => '{rust}'],
        // The items of a property declaration, which php-parser calls `$node->props`.
        'property-items' => ['iter' => '{rust}.iter()', 'item' => 'property-item', 'phpIter' => '{rust}'],
        'collected' => ['iter' => '{rust}', 'item' => 'collected-item'],
        'collected-item' => ['iter' => '{rust}', 'item' => 'collected-item'],
        'const-items' => ['iter' => '{rust}.iter()', 'item' => 'const-item', 'phpIter' => '{rust}'],
        'hint-parts' => ['iter' => '{rust}', 'item' => 'hint'],
        'property-members' => ['iter' => '{rust}', 'item' => 'property'],
        // A call's arguments, one wrapped `Argument` each. Iterated to ask a question of every argument —
        // `lastBareFlagIndex()` asks whether any is named or spread, because either breaks the mapping from
        // argument position to parameter position.
        'args' => ['iter' => self::PHP_ONLY, 'item' => 'argument', 'phpIter' => 'Support::arguments({rust})'],
    ];

    /**
     * A collector class -> the aggregate it contributes to.
     *
     * The one thing about a collector-and-consumer pair that cannot be read from the rule's own source. The
     * message, the identifier and the configured threshold all come from the rule and its package; *which
     * measurement it is* is a fact about the collector, so it is named here.
     *
     * A consumer naming a collector this table does not know is refused. Emitting a guess would report a
     * percentage of something nobody chose.
     *
     * Only entries with a **verified differential** are listed. `TypeCoverage` implements the return,
     * property and declare metrics too, and each was written from the same reading of the collectors — but
     * only the parameter metric has been compared against the real rule on a real project, and that
     * comparison took five corrections to reach agreement, three of them arithmetic nobody would guess.
     * Mapping a metric whose numbers have not been checked would ship exactly the plausible-but-wrong rule
     * this tool refuses to emit. Each becomes an entry here when its differential passes.
     *
     * @var array<string, string>
     */
    public const array AGGREGATES = [
        'ParamTypeDeclarationCollector' => 'parameters',
    ];

    /**
     * Why a mapped aggregate is not emitted by default, or null once it agrees with the original.
     *
     * The parameter metric agreed exactly with the original on a small fixture and then disagreed on a
     * 585-file dependency tree. Keeping the mapping and refusing here — rather than dropping the mapping —
     * means the refusal names the real obstacle and a number, instead of saying nothing was mapped.
     *
     * A method rather than a table: every mapped metric happens to be withheld today, and a constant would let
     * a static analyser prove the emission unreachable and report it as dead code, which it is not.
     *
     * An entry disappears from here when a *corpus* differential agrees, not a fixture one.
     */
    public static function unverifiedAggregate(string $metric): ?string
    {
        return match ($metric) {
            'parameters' => 'the parameter aggregate disagrees with the original at corpus scale: on 585 files '
                . 'PHPStan counted 4057 parameters with 1994 typed (49.1%) where this counts 3079 with 2927 '
                . '(95.0%). Two known causes: only class methods are counted, where the collector targets every '
                . 'FunctionLike; and ParameterMetadata->declaredType is not php-parser\'s native $param->type',
            default => null,
        };
    }

    /** PHPStan node class -> the support predicate that recognises it. */
    /**
     * @var array<class-string, string>
     */
    public const array NODE_PREDICATES = [
        Name::class => 'is_name',
        Variable::class => 'is_variable',
        PropertyFetch::class => 'is_property_fetch',
        MethodCall::class => 'is_method_call',
        Array_::class => 'is_array',
        Int_::class => 'is_int',
        ArrayDimFetch::class => 'is_array_dim_fetch',
    ];

    /**
     * A bare `! $x instanceof K` guard is not a test, it is a refinement: in Rust the idiomatic form
     * binds the narrowed node and returns early. Each entry says how to bind, and which of the PHP
     * node's properties the binding then stands for.
     */
    /**
     * @var array<class-string, array{adapter: string, field?: string, fields?: array<string, array{0: string, 1: string, 2?: string}>}>
     */
    public const array REFINEMENTS = [
        // adapter yields the node itself, so its fields are reachable
        MethodCall::class => ['adapter' => 'as_method_call', 'fields' => [
            // Rust reads a field off the bound node; PHP navigates from it, so the bound Part is the base.
            'var' => ['{bind}.object', 'expr', 'Support::nthExpression($context, {bind}, 0)'],
            'name' => ['&{bind}.method', 'name-selector', 'Support::selector($context, {bind})'],
        ]],
        // adapter yields one field directly; that field is all the rule can reach
        ArrayDimFetch::class => ['field' => 'var', 'adapter' => 'dim_fetch_target'],
        PropertyFetch::class => ['field' => 'var', 'adapter' => 'property_fetch_target'],
    ];
}
