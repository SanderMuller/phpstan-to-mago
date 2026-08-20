<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Node\FileNode;
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
     * @var array<class-string, array{trait: string, method: string, node: string|null, kind: string, adapter?: string, extra?: string, classFrom?: string, classOnly?: bool, each?: string, phpOnly?: bool, gate?: string}>
     */
    public const array HOOKS = [
        MethodCall::class => ['trait' => 'MethodCallHook', 'method' => 'after_method_call', 'node' => 'MethodCall', 'kind' => 'MethodCall'],
        // `$obj?->m(..)` is a node kind of its own in Mago, probed rather than assumed: the `MethodCall` hook
        // does not fire for it. That also settles a question PHPStan has to guard against — it dispatches a
        // *synthetic* `MethodCall` for the non-null branch, which is why its rules check a
        // `virtualNullsafeMethodCall` attribute to avoid reporting twice. There is no synthetic node here.
        //
        // PHP target only: Mago's Rust side has its own hook trait for this and nothing in the corpus has
        // pinned down which, so the Rust targets refuse by name rather than register against a guess.
        NullsafeMethodCall::class => ['trait' => 'NullSafeMethodCallHook', 'method' => 'after_null_safe_method_call', 'node' => 'NullSafeMethodCall', 'kind' => 'NullSafeMethodCall', 'phpOnly' => true],
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
        // A closure, which the Symfony config rules target: a config file *is* a closure taking a
        // `ContainerConfigurator`, so the whole family gates on the declaration itself. PHP target only, for the
        // same reason as the nullsafe hook — the Rust trait for it is not pinned down by anything in the corpus.
        Closure::class => ['trait' => 'ClosureHook', 'method' => 'after_closure', 'node' => 'Closure', 'kind' => 'Closure', 'phpOnly' => true],
        // A trait declaration. Separate from the class hook because Mago makes it a separate node kind, and
        // because a rule that targets `Trait_` means traits only — `NoRequiredOutsideClassRule` exists to say
        // that a `#[Required]` setter belongs in a class, not in a trait.
        Trait_::class => ['trait' => 'TraitDeclarationHook', 'method' => 'on_enter_trait', 'node' => 'Trait', 'kind' => 'Trait', 'phpOnly' => true],
        // A `foreach` statement. Probed: the hook fires for a nested one too, which is what PHPStan does — a rule
        // registered for `Foreach_` runs on every one of them, so agreement depends on that matching.
        Foreach_::class => ['trait' => 'ForeachHook', 'method' => 'after_foreach', 'node' => 'Foreach', 'kind' => 'Foreach', 'phpOnly' => true],
        // PHPStan's virtual whole-file node. Mago's `Program` is the CST root, so a hook on it fires once per
        // file, which is what a rule asking a question about the file as a whole needs. PHP target only, like
        // the other kinds whose Rust trait nothing in the corpus has pinned down.
        FileNode::class => ['trait' => 'ProgramHook', 'method' => 'after_program', 'node' => 'Program', 'kind' => 'Program', 'phpOnly' => true],
        // String concatenation. Mago has one `Binary` kind for every binary operator rather than a node class
        // per operator, so the hook fires for arithmetic and comparison too and the operator itself is a child
        // node — which is why `left`/`right` here are the operands *of a concatenation*, and a rule reaching
        // them is asking about one only after the operator has been checked.
        Concat::class => [
            'trait' => 'BinaryHook', 'method' => 'after_binary', 'node' => 'Binary', 'kind' => 'Binary',
            'gate' => "Support::binaryOperatorIs(\$context, \$node, '.')", 'phpOnly' => true,
        ],
    ];

    /**
     * php-parser node classes a rule searches a subtree for, and the Mago kinds each covers.
     *
     * A refuse-by-default table rather than a convenience mapping, for two reasons. Some php-parser classes are
     * *abstract* — `ClassLike` means class, interface, trait or enum, four kinds here — so the relationship is not
     * one to one and cannot be derived. And the hook table is not a substitute: a hook's kind and a search's kind
     * coincide for `Foreach` and diverge for `New_`, which hooks through an expression adapter and searches as
     * `Instantiation`. A class absent from here is refused by name.
     *
     * @var array<class-string, list<string>>
     */
    public const array SEARCHABLE = [
        Foreach_::class => ['Foreach'],
        Return_::class => ['Return'],
        MethodCall::class => ['MethodCall'],
        StaticCall::class => ['StaticMethodCall'],
        FuncCall::class => ['FunctionCall'],
        New_::class => ['Instantiation'],
        String_::class => ['LiteralString'],
        ClassLike::class => ['Class', 'Interface', 'Trait', 'Enum'],
    ];

    /**
     * A php-parser node class whose kinds answer a field the same way, and the FIELDS group that says how.
     *
     * A search for `ClassLike` finds four kinds, so no single kind's fields apply — but all four carry their
     * name as a `LocalIdentifier` child, so `->name` has one answer for the group. Only fields that are the
     * same for every kind in the group belong under it.
     *
     * @var array<class-string, string>
     */
    public const array FIELD_GROUPS = [
        ClassLike::class => 'ClassLike',
    ];

    /**
     * Where a PHPStan node's property lives on the Mago node, per node kind.
     *
     * A PHP template navigates from `{base}`, the node being asked, rather than from the hook's own `$node`. The
     * same field means the same thing wherever the node came from — a rule that finds a method call in a subtree
     * asks it for its arguments exactly as a rule hooked on one does — and hardcoding `$node` made every one of
     * these answer about the wrong node as soon as the subject was not the hook's.
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
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, {base})'],
        ],
        // The same three children in the same order, which the CST probe confirmed rather than assumed: there
        // is no extra node for the `?->` token to shift the positions.
        'NullSafeMethodCall' => [
            'var' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)'],
            'name' => [self::PHP_ONLY, 'name-selector', 'Support::selector($context, {base})'],
        ],
        'FunctionCall' => [
            'name' => ['node.function', 'name-expr', 'Support::nthExpression($context, $node, 0)'],
        ],
        'StaticMethodCall' => [
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, {base})'],
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, {base})'],
        ],
        'Assignment' => [
            // Both sides are an `Expression` child, told apart only by position.
            'var' => ['node.lhs', 'expr', 'Support::nthExpression($context, $node, 0)'],
            'expr' => ['node.rhs', 'expr', 'Support::nthExpression($context, $node, 1)'],
        ],
        'Class' => [
            'extends' => ['node', 'extends'],
            // The name as written, short: `$node->name->toString()` on a declaration gives `Something`, not
            // `App\Something`, which is what a rule testing a prefix or a suffix compares against.
            'name' => [self::PHP_ONLY, 'bytes', 'Support::declarationName($context, {base})'],
        ],
        // The group a `ClassLike` search yields: class, interface, trait or enum, which all name themselves
        // the same way. `class-like-name` is its own kind rather than plain bytes because the only question
        // asked of it is php-parser's `name instanceof Identifier`, and that question has a structural answer
        // here — see the instanceof handling.
        // Mago's `Binary` holds its operands as `Expression` children either side of the operator, which the
        // helpers read by position — probed on `__DIR__ . '/x.php'`, which is `Expression(MagicConstant),
        // BinaryOperator ., Expression(Literal)`.
        'Binary' => [
            'left' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 0)'],
            'right' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 1)'],
        ],
        'ClassLike' => [
            'name' => [self::PHP_ONLY, 'class-like-name', 'Support::declarationName($context, {base})'],
        ],
        'ClassConstantAccess' => [
            // Rust reads the field; the PHP SDK's Node has no fields, so the class part is found by
            // walking children. A class-constant access has an Identifier for the class and a
            // ClassLikeConstantSelector for the constant, so the first Identifier is the class.
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, {base})'],
        ],
        'ClassLikeConstant' => [
            'consts' => ['node.items', 'const-items', 'Support::constantItems($context, {base})'],
        ],
        'Property' => [
            'type' => ['support::property_hint(node)', 'hint-option', 'Support::propertyHint($context, {base})'],
        ],
        'Method' => [
            'name' => ['&node.name', 'local-name'],
        ],
        'Instantiation' => [
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, {base})'],
        ],
        // `return;` has no expression child at all, which is what makes `$return->expr === null` the question a
        // rule asks — probed across `return null;`, `return 1 + 2;` and a bare `return;`.
        'Return' => [
            'expr' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 0)'],
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
        // A method declaration as written, which is what a rule looping a class-like's body holds. `->name` is
        // the written name; the visibility, staticness and magic-ness are read from the modifiers and the name,
        // because this is the declaration rather than the metadata a reflection lookup returns.
        'method-decl' => [
            'name' => [self::PHP_ONLY, 'method-name', '{base}'],
        ],
        'attr-group' => [
            'attrs' => [self::PHP_ONLY, 'attributes', 'Support::attributesOf({base})'],
        ],
        'attribute' => [
            'name' => [self::PHP_ONLY, 'attribute-name', '{base}'],
        ],
        // A parameter of a declaration, as written — not the metadata a reflection lookup returns. `->type` is
        // the hint, which is absent for an untyped parameter, so it goes through the option-tolerant kind.
        'param-decl' => [
            'type' => [self::PHP_ONLY, 'hint-option', 'Support::declaredParamHint({base})'],
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
        // What a subtree search found, one node each. `expr` because what a rule does with a found node is ask
        // the same things it asks of any expression it navigated to.
        'found-nodes' => ['iter' => self::PHP_ONLY, 'item' => 'expr', 'phpIter' => '{rust}'],
        // The method declarations of a class-like body, one `method-decl` each.
        'method-members' => ['iter' => self::PHP_ONLY, 'item' => 'method-decl', 'phpIter' => '{rust}'],
        // php-parser models attributes in two levels: a declaration carries attribute *groups*, each holding
        // attributes. Both levels are iterables here because a rule asking "does it carry this attribute" writes
        // both loops, and flattening them would be inventing a shape the source does not have.
        'attr-groups' => ['iter' => self::PHP_ONLY, 'item' => 'attr-group', 'phpIter' => '{rust}'],
        'attributes' => ['iter' => self::PHP_ONLY, 'item' => 'attribute', 'phpIter' => '{rust}'],
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
        // Both PHP-target only, and both take the context because the answer is a node kind rather than
        // anything readable from the part alone.
        Dir::class => 'is_dir_constant',
        String_::class => 'is_literal_string',
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
