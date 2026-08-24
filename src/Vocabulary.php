<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
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
use PhpParser\Node\Stmt\Function_;
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
        // `FunctionLike` is an interface, so a rule naming it asks for every function-like there is and
        // branches on the concrete one. The primary kind is `Method` because that is the shape the fields are
        // keyed by; `HOOK_KINDS` says which targets the plugin registers.
        FunctionLike::class => ['trait' => 'ClassLikeMemberHook', 'method' => 'on_method', 'node' => 'Method', 'kind' => 'Method', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata', 'phpOnly' => true],
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
        // An array literal. A rule reaching one asks about its elements' inferred types, which a node hook can
        // request; the elements themselves are wrapped in an `ArrayElement` category node, and the type is
        // available at both that level and the `ValueArrayElement` beneath it.
        Array_::class => [
            'trait' => 'ArrayHook', 'method' => 'after_array', 'node' => 'Array', 'kind' => 'Array',
            'phpOnly' => true,
        ],
        // A rule naming the abstract `Expr` asks PHPStan for every expression and branches on the concrete
        // ones — `NoDynamicNameRule` calls that "a trick to allow multiple node types" in its own comment. A
        // plugin registers all six kinds and the body's `instanceof` branches become kind tests. The fields
        // below answer for every one of them, which is what makes one `kind` enough: `->name` is a selector
        // under five and the called expression under `FunctionCall`, and `namePart()` covers both.
        Expr::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'Expr', 'phpOnly' => true],
        // `CallLike` is `FuncCall`, `MethodCall`, `NullsafeMethodCall`, `StaticCall` and `New_`, and a rule
        // asking for it narrows in its own body. Registered for every call kind it covers rather than for the
        // ones a given rule keeps, for the reason {@see HOOK_KINDS} gives: what a node type covers is a fact
        // about the type, and letting the body decide the registration makes the targets depend on branches.
        CallLike::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'MethodCall', 'phpOnly' => true],
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
        // The elements of an array literal, each wrapped in the `ArrayElement` category node. `items` is what
        // php-parser calls them, and `count($node->items)` is the question a rule asks first.
        'Array' => [
            'items' => [self::PHP_ONLY, 'array-items', 'Support::arrayElements($context, {base})'],
        ],
        // The group a `ClassLike` search yields: class, interface, trait or enum, which all name themselves
        // the same way. `class-like-name` is its own kind rather than plain bytes because the only question
        // asked of it is php-parser's `name instanceof Identifier`, and that question has a structural answer
        // here — see the instanceof handling.
        // Mago's `Binary` holds its operands as `Expression` children either side of the operator, which the
        // helpers read by position — probed on `__DIR__ . '/x.php'`, which is `Expression(MagicConstant),
        // BinaryOperator ., Expression(Literal)`.
        // The `Expr` family: fields every kind it registers answers the same way. `class` is the part before
        // `::`, which only the two static accesses have and which reads as the first expression child for both.
        'Expr' => [
            'class' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 0)'],
            'name' => [self::PHP_ONLY, 'name-part', 'Support::namePart($context, {base})'],
        ],
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
            // The declaration's own name. `declarationName()` reads the first identifier child, which for a
            // method declaration is the method name — modifiers are keywords, not identifiers.
            'name' => ['&node.name', 'local-name', 'Support::declarationName($context, {base})'],
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
        // A method reached by name, which may not exist. The fields are the same; what differs is that
        // `instanceof ClassMethod` on it is a real null check rather than a narrowing that always holds.
        'maybe-method-decl' => [
            'name' => [self::PHP_ONLY, 'method-name', '{base}'],
        ],
        // A property item — one declared name in `protected $a = 1, $b = 2;`. Probed: an initialised item wraps
        // its value in an `Expression`, an uninitialised one has no such child, so the read is null-tolerant and
        // the rule's own `instanceof` is what tells the two apart.
        'property-item' => [
            'default' => [self::PHP_ONLY, 'property-default', 'Support::propertyItemDefault({base})'],
        ],
        // Kept apart from a bare expression so `->items` is only offered where a property default is what was
        // asked for. Reading it of anything else would answer "no elements" for a value that is not a list.
        'property-default' => [
            'items' => [self::PHP_ONLY, 'array-items', 'Support::arrayElements($context, {base})'],
        ],
        // A constant declaration as written, which is what `getConstants()` hands a rule. Its items are read
        // the same way the `ClassLikeConstant` hook reads them.
        'const-decl' => [
            'consts' => [self::PHP_ONLY, 'const-items', 'Support::constantItems($context, {base})'],
        ],
        'const-item' => [
            'value' => [self::PHP_ONLY, 'expr', 'Support::constantItemValue($context, {base})'],
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
        // The constant *declarations* a class-like holds, each of which holds its own items.
        'const-decls' => ['iter' => self::PHP_ONLY, 'item' => 'const-decl', 'phpIter' => '{rust}'],
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
        // The names of the classes a declaration extends, one written name each.
        'class-names' => ['iter' => self::PHP_ONLY, 'item' => 'class-name', 'phpIter' => '{rust}'],
        // Attribute names a declaration carries, already resolved and *not* lowercased — kept apart from
        // `class-names` for exactly that reason: anything comparing against one of those folds case, and these
        // match a written attribute name as it stands.
        'attribute-names' => ['iter' => self::PHP_ONLY, 'item' => 'bytes', 'phpIter' => '{rust}'],
        // The elements of an array literal, one wrapped element each.
        'array-items' => ['iter' => self::PHP_ONLY, 'item' => 'expr', 'phpIter' => '{rust}'],
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
     * A collaborator method whose answer a runtime helper computes, rather than one whose body is inlined.
     *
     * Most collaborators in these packages are small and their methods inline fine. One is not: the
     * cognitive-complexity analyzer is a php-parser `NodeTraverser` driving two stateful visitors, and there is
     * no statement-by-statement translation of that. The *answer* is reproducible exactly, which
     * `internal/oracle-cognitive-complexity.php` measures against the real analyzer — 0 disagreements over
     * 5414 methods of real code — so the helper stands in for the method and everything around it still comes
     * from the rule's own source.
     *
     * Keyed by the collaborator's fully qualified name and method, for the reason
     * {@see CROSS_FILE_CHECKS} is: a short name cannot be checked and a package is data here, never an import.
     *
     * The call may be on a collaborator property or on the rule itself — `$this->resolveFunctionName(…)` is
     * the same question one owner along, so the key is the declaring class either way rather than a second
     * table. `takes` says what the helper's first argument is: `source` for one that reads only the tree,
     * `context` for one that also asks the analysis something. `arguments` are the call-site positions to
     * forward, because a helper needs what the original's method needed *of the tree* and not its `Scope` —
     * forwarding all of them refused on `unknown local $scope`, which is the analysis object the helper exists
     * to avoid needing.
     *
     * @var array<string, array{helper: string, kind: string, takes: string, arguments: list<int>}>
     */
    public const array COLLABORATOR_CALLS = [
        'TomasVotruba\CognitiveComplexity\AstCognitiveComplexityAnalyzer::analyzeFunctionLike' => [
            'helper' => 'CognitiveComplexity::forFunctionLike',
            'kind' => 'int',
            'takes' => 'source',
            'arguments' => [0],
        ],
        'TomasVotruba\CognitiveComplexity\AstCognitiveComplexityAnalyzer::analyzeClassLike' => [
            'helper' => 'CognitiveComplexity::forClassLike',
            'kind' => 'int',
            'takes' => 'source',
            'arguments' => [0],
        ],
        'TomasVotruba\CognitiveComplexity\Rules\FunctionLikeCognitiveComplexityRule::resolveFunctionName' => [
            'helper' => 'Support::functionLikeName',
            'kind' => 'bytes',
            'takes' => 'context',
            'arguments' => [0],
        ],
    ];

    /**
     * A check whose question no node hook can answer, and the whole-project pass that answers it instead.
     *
     * The same shape as {@see AGGREGATES} one level down. There the rule's *source* cannot be translated
     * because PHPStan collects across files and Mago has no collector; here one *check* of a rule cannot,
     * because it reads a method body that may be declared in another file. `getFileName()` plus
     * `Parser::parseFile()` is PHPStan's route; the SDK's is `getDeclaringMethod()` plus that file's CST, and
     * `internal/probe-declaring-file-body.php` measured that only an after-analysis pass can take it.
     *
     * Keyed by the declaring class-like's fully qualified name and the method, because that is what a rule's
     * own source names and it cannot collide across corpora. This transpiler does not run php-parser's
     * `NameResolver`, so the namespace travels with the declaration from `SourceIndex` rather than off the
     * node. `arguments` are call-site argument positions, rendered by the ordinary resolver — the accessor
     * list and namespaces come from the rule, not from here.
     *
     * What is named here is only what the source cannot say: *which* pass answers the question.
     *
     * A mapped aggregate is held to a *corpus* differential — it agrees, or it states the bound it is off by,
     * or it refuses. There is no counterpart here because that bar cannot be met, for a measured reason rather
     * than an assumed one. `../hihaho` reports nothing under `unvalidatedFormRequestField`, because every `rules()`
     * on a class extending a request base there is conditional and so opaque to the original too. A corpus
     * differential would agree on zero, and two tools agreeing on nothing is the one result that proves
     * neither looked.
     *
     * So the proof is split, and both halves ran. The corpus differential answers the *negative* direction —
     * no false positive on real code. The fires-gate answers the positive one, and it is not a snapshot: it
     * runs the real rule under real PHPStan against the emitted plugin under real mago and compares line and
     * message, including one example whose `rules()` is declared in another file. Breaking the resolver on
     * purpose turns that comparison red and names the two findings that vanish, which is what says the pass
     * is load-bearing rather than incidental. An entry whose pass ever *does* disagree gets the aggregate
     * treatment then, with its number: {@see ACCEPTED_DIVERGENCE} where the cause is named and unportable,
     * {@see unverifiedAggregate()} where it is not.
     *
     * @var array<string, array{pass: string, arguments: list<int>}>
     */
    public const array CROSS_FILE_CHECKS = [
        'Hihaho\PhpstanRules\Traits\ResolvesFormRequestRuleKeys::unvalidatedFormRequestFieldError' => [
            'pass' => 'FormRequestFields::report',
            'arguments' => [4, 5, 6],
        ],
        'Hihaho\PhpstanRules\Traits\DetectsFacadeAlias::facadeAliasError' => [
            'pass' => 'FacadeAliases::report',
            'arguments' => [],
        ],
    ];

    /**
     * The divergence a mapped aggregate is emitted *with*, where it does not reach exact agreement.
     *
     * The parameter metric agreed exactly on a small fixture, then disagreed on a 585-file dependency tree,
     * and was withheld while the gap was traced. Every remaining part of the gap has the same cause and that
     * cause is not portable, so the honest outcome is a bound rather than a refusal — refusing forever on
     * something the port cannot close blocks the rule permanently for nothing.
     *
     * **The measurement, and what it is against.** `php tests/Support/run-coverage-corpus.php <consumer-root>`
     * on two Laravel consumers. On hihaho (2933 files) PHPStan counts 13694 parameters where this counts
     * 13775; on mijntp (4372 files) 11428 against 11465. Both single-signed, so the port never under-counts.
     * Independently, `type-coverage`'s own param count on the first consumer is 11164 today and 7317 with two
     * pending semantics fixes applied — a different measurement against a different extension set, quoted
     * because it sizes the *worst case*: the same +81 is 0.73% of that denominator now and 1.11% after those
     * fixes land. `CEILING` is set against the post-fix figure so landing them cannot turn the gate red
     * without a real regression.
     *
     * **The one cause, three times.** The collector's LSP guard reads `ClassReflection::hasMethod()`, and
     * PHPStan answers that from reflection mago cannot see.
     *
     * - 56 of hihaho's 81 and 16 of mijntp's 37 sit in `database/factories`, and mijntp has exactly 16 factory
     *   methods named `for*` or `has*`: larastan's `ModelFactoryMethodsClassReflectionExtension` answering for
     *   a relation the model declares. Controlled both ways — the same method is skipped when the factory
     *   annotates `@extends Factory<Model>` and counted when it does not.
     * - Another 12 of hihaho's is three classes implementing PHPStan reflection-extension interfaces that ship
     *   inside `phpstan.phar`, which mago cannot resolve either.
     * - The +11 left over `app/` is the same thing a third time: +12 of it is the auth model, because
     *   larastan's `AuthsMethodsExtension` answers `hasMethod()` on `Illuminate\Contracts\Auth\Authenticatable`
     *   by looking the name up on the configured auth model. Controlled to the name: in a class implementing
     *   only that contract, a method named after one the auth model has is skipped and an invented name is
     *   counted.
     *
     * Reproducing any of it means reproducing every installed reflection extension, which a Mago plugin cannot
     * do. `CountsParametersLikeTheCollectorTest` holds the mechanism itself still, with a control that
     * registers such an extension and pins the exact divergence, so a *widening* of it is caught in CI rather
     * than at the next corpus run.
     * `php tests/Support/run-coverage-setdiff.php <consumer-root> <file>` names the declarations behind any of
     * these.
     *
     * `NOTE` is what the emitted plugin carries, so a reader of the generated rule finds the bound without
     * finding this file.
     *
     * @var array<string, array{ceiling: float, note: string}>
     */
    public const array ACCEPTED_DIVERGENCE = [
        'parameters' => [
            'ceiling' => 0.0111,
            'note' => 'Over-counts the original by up to 1.11%, and never under-counts. The collector skips a '
                . 'method whose name an ancestor has, and PHPStan answers that from reflection extensions a '
                . 'Mago plugin cannot reproduce. Measured on two Laravel consumers: +81 of 13694 and +37 of '
                . '11428. Reproduce with `php tests/Support/run-coverage-corpus.php <consumer-root>`.',
        ],
    ];

    /**
     * Why a mapped aggregate is not emitted by default, or null once it is accepted.
     *
     * Nothing is withheld today: the parameter metric moved to {@see ACCEPTED_DIVERGENCE} once its whole gap
     * traced to one unportable cause. The route is kept rather than deleted because it is the one a *future*
     * disagreeing aggregate takes, and {@see CROSS_FILE_CHECKS} already points here for that — a metric whose
     * gap has no named cause has to refuse, not carry a bound it cannot justify.
     */
    public static function unverifiedAggregate(string $metric): ?string
    {
        /**
         * Metric to the refusal it owes. Empty today, and a table rather than a `match` so that emptying it
         * reads as "nothing is withheld" instead of as a branch a reader has to check for a fall-through.
         *
         * @var array<string, string> $withheld
         */
        $withheld = [];

        return $withheld[$metric] ?? null;
    }

    /** PHPStan node class -> the support predicate that recognises it. */
    /**
     * @var array<class-string, string>
     */
    /**
     * The Mago node kind each php-parser expression class is, for a rule that branches on the concrete one.
     *
     * Only needed by the `Expr` family: a rule registering every expression tests `instanceof MethodCall` and
     * the like, and each test becomes a node-kind test. Kept apart from `HOOKS` because two of these have no
     * hook of their own — nothing registers for a property access alone — and a table of hooks is the wrong
     * place to say what one *is*.
     *
     * @var array<class-string, string>
     */
    /**
     * The node kinds a rule's declared node type covers, where it covers more than one.
     *
     * Only for an *abstract* php-parser class: a rule returning one asks PHPStan for every node beneath it and
     * branches on the concrete kind, so the plugin registers each. Kept apart from `HOOKS` because a hook row
     * is strings and putting a list in one loosens the type for every reader of it.
     *
     * Every expression kind is registered, not only the ones a rule's branches name — PHPStan really does visit
     * them all, and a branch declining a kind is the rule's own business.
     *
     * @var array<class-string, list<string>>
     */
    public const array HOOK_KINDS = [
        Expr::class => ['ClassConstantAccess', 'StaticPropertyAccess', 'MethodCall', 'StaticMethodCall', 'FunctionCall', 'PropertyAccess'],
        // The three call kinds share their children exactly — `Expression`, `ClassLikeMemberSelector`,
        // `ArgumentList`, in that order, probed on all of them — which is why one body reads all three
        // without rebinding. A first-class callable is a *different* kind (`MethodPartialApplication`), so a
        // hook on these never sees one, and `isFirstClassCallable()` cannot hold under these targets.
        CallLike::class => ['MethodCall', 'StaticMethodCall', 'NullSafeMethodCall', 'FunctionCall'],
        // All four, not the two a given rule narrows to: the kinds a node type *covers* are a fact about the
        // type, and letting a rule's own `instanceof` decide the registration would make the targets depend on
        // the body rather than on what PHPStan would have visited.
        FunctionLike::class => ['Method', 'Function', 'Closure', 'ArrowFunction'],
    ];

    public const array EXPRESSION_KINDS = [
        ClassConstFetch::class => 'ClassConstantAccess',
        StaticPropertyFetch::class => 'StaticPropertyAccess',
        MethodCall::class => 'MethodCall',
        StaticCall::class => 'StaticMethodCall',
        FuncCall::class => 'FunctionCall',
        PropertyFetch::class => 'PropertyAccess',
    ];

    public const array NODE_PREDICATES = [
        Name::class => 'is_name',
        Variable::class => 'is_variable',
        PropertyFetch::class => 'is_property_fetch',
        MethodCall::class => 'is_method_call',
        StaticCall::class => 'is_static_call',
        // Both added by auditing what a refusal names: two `symplify` config-closure rules ask these of an
        // argument's value, and the refusal said "no node predicate for instanceof FuncCall on a expr" — which
        // reads as a predicate that exists for other positions and did not exist at all.
        FuncCall::class => 'is_function_call',
        ClassConstFetch::class => 'is_class_constant_access',
        Array_::class => 'is_array',
        Int_::class => 'is_int',
        // Declaration kinds a rule narrows a function-like hook to. Answered from the node's own kind, which
        // is what makes the same predicate serve every kind the hook registers.
        ClassMethod::class => 'is_method_declaration',
        Function_::class => 'is_function_declaration',
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
