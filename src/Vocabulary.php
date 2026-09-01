<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
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
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\While_;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Node\FileNode;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClassNode;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;

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
        // First-class callable syntax. PHPStan gives `$o->m(...)` a virtual node of its own rather than a
        // `MethodCall`, and Mago gives it a node kind of its own rather than a `MethodCall` — the two agree,
        // which is what makes the mapping exact. Probed: `MethodPartialApplication` carries the same three
        // children a call does, in the same order, under a `PartialApplication` category node that is *not*
        // registered here — a hook taking both would report every finding twice.
        //
        // PHP target only, for the reason the nullsafe row gives: nothing in the corpus pins down which Rust
        // hook trait fires for these, and registering against a guess is worse than a refusal that names it.
        MethodCallableNode::class => ['trait' => 'MethodPartialApplicationHook', 'method' => 'after_method_partial_application', 'node' => 'MethodPartialApplication', 'kind' => 'MethodPartialApplication', 'phpOnly' => true],
        // The two member accesses the `VariableVariables` family reads, which Mago spells `Access` rather than
        // `Fetch`. Registered on the specific kind, never on the `Access` category node above it, for the same
        // reason the partial-application rows are: a hook taking both reports twice.
        PropertyFetch::class => ['trait' => 'PropertyAccessHook', 'method' => 'after_property_access', 'node' => 'PropertyAccess', 'kind' => 'PropertyAccess', 'phpOnly' => true],
        // An attribute as the node a rule fires on, rather than one reached from a declaration. Mago gives it
        // a kind of its own, and the two children a rule reads — the name and the argument list — are the ones
        // the `attribute` part row already reads, so the fields are the same navigation from the hook's node.
        Attribute::class => ['trait' => 'AttributeHook', 'method' => 'after_attribute', 'node' => 'Attribute', 'kind' => 'Attribute', 'phpOnly' => true],
        StaticPropertyFetch::class => ['trait' => 'StaticPropertyAccessHook', 'method' => 'after_static_property_access', 'node' => 'StaticPropertyAccess', 'kind' => 'StaticPropertyAccess', 'phpOnly' => true],
        StaticMethodCallableNode::class => ['trait' => 'StaticMethodPartialApplicationHook', 'method' => 'after_static_method_partial_application', 'node' => 'StaticMethodPartialApplication', 'kind' => 'StaticMethodPartialApplication', 'phpOnly' => true],
        // PHPStan's virtual per-method node, the method-level counterpart of `InClassNode`. Mapped to the same
        // hook as `ClassMethod`, because that is the declaration it stands for: PHPStan copies the original
        // node's attributes onto it, so `getDocComment()` and the line a finding lands on are the method's.
        // Measured rather than assumed — the real rule anchors a bad method annotation on the `function` line,
        // which is the node this hook fires for.
        InClassMethodNode::class => ['trait' => 'ClassLikeMemberHook', 'method' => 'on_method', 'node' => 'Method', 'kind' => 'Method', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
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
        //
        // This carried `phpOnly` until the analyzer registry was read rather than assumed: `register_trait_hook`
        // and `TraitDeclarationHook::on_enter_trait` are both there at the pinned 1.47.2, taking the same
        // `&ClassLikeMetadata` the class hook takes, which is why the signature now says so.
        Trait_::class => ['trait' => 'TraitDeclarationHook', 'method' => 'on_enter_trait', 'node' => 'Trait', 'kind' => 'Trait', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
        // An interface declaration. `CheckRequiredInterfaceInContractNamespaceRule` is the one in the corpus,
        // and it refused on all three targets for want of this row rather than for anything in its body.
        // The php-parser base of all four class-like declarations. `HOOK_KINDS` says which kinds it covers and
        // the PHP target registers a plugin for each, so this row needs no machinery beyond itself. PHP target
        // only, like every other multi-kind row: one Rust hook trait registers one kind, and registering just
        // the class hook would silently miss the interfaces and traits the rule exists to check.
        ClassLike::class => ['trait' => 'ClassDeclarationHook', 'method' => 'on_enter_class', 'node' => 'Class', 'kind' => 'Class', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata', 'phpOnly' => true],
        // A bare constant read -- `PHP_EOL`, `FILTER_SANITIZE_STRING`. Mago spells it `ConstantAccess`, and
        // the name is the node's own text rather than a child selector. PHP target only, like the other rows
        // whose Rust hook trait nothing in the corpus has pinned down.
        ConstFetch::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'ConstantAccess', 'phpOnly' => true],
        Interface_::class => ['trait' => 'InterfaceDeclarationHook', 'method' => 'on_enter_interface', 'node' => 'Interface', 'kind' => 'Interface', 'extra' => ', {metadata}: &ClassLikeMetadata', 'classFrom' => 'metadata'],
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
        // Two constructs a rule forbids outright: the body has no guards at all, so the hook *is* the rule.
        // Probed rather than assumed — `empty($a)` arrives as `EmptyConstruct` and a backtick as
        // `ShellExecuteString`, each once, which is what a rule reporting on every one of them needs.
        //
        // PHP target only, like the other kinds whose Rust trait nothing in the corpus has pinned down.
        Empty_::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'EmptyConstruct', 'phpOnly' => true],
        ShellExec::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'ShellExecuteString', 'phpOnly' => true],
        // The five statements and two expressions whose *condition* a rule asks about. Child positions probed
        // rather than counted from php-parser: `do { } while ($c);` puts its condition fourth among the
        // children and first among the `Expression` ones, which is the index {@see Support::nthExpression}
        // uses, and a `?:` puts its condition first of three.
        //
        // php-parser's `ElseIf_` is Mago's `IfStatementBodyElseIfClause`, and the colon-delimited spelling is
        // its own kind — both registered, because `if (..): elseif (..): endif;` is the same rule's business.
        If_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'kind' => 'If', 'phpOnly' => true],
        ElseIf_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'kind' => 'IfStatementBodyElseIfClause', 'phpOnly' => true],
        While_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'kind' => 'While', 'phpOnly' => true],
        Do_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'kind' => 'DoWhile', 'phpOnly' => true],
        Switch_::class => ['trait' => 'StatementHook', 'method' => 'after_statement', 'node' => 'Statement', 'kind' => 'Switch', 'phpOnly' => true],
        Ternary::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'Conditional', 'phpOnly' => true],
        BooleanNot::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'UnaryPrefix', 'gate' => "Support::unaryOperatorIs(\$context, \$node, '!')", 'phpOnly' => true],
        // A variable, in the three shapes Mago gives one. `$x` is a `DirectVariable`; `$$n` is a
        // `NestedVariable` holding one, and `${expr}` an `IndirectVariable` — probed, with `$$n` producing a
        // `NestedVariable` and then a `DirectVariable` for the inner `$n`. All three registered, because the
        // rule that reaches here asks which shape it is rather than narrowing before the hook.
        Variable::class => ['trait' => 'ExpressionHook', 'method' => 'after_expression', 'node' => 'Expression', 'kind' => 'DirectVariable', 'phpOnly' => true],
        // php-parser's attribute *group* is mago's `AttributeList`. A rule registered for it asks about the
        // attributes inside, which the group-level navigation below already answers.
        AttributeGroup::class => [
            'trait' => 'AttributeListHook', 'method' => 'after_attribute_list', 'node' => 'AttributeList',
            'kind' => 'AttributeList', 'phpOnly' => true,
        ],
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
        'If' => ['cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        'IfStatementBodyElseIfClause' => ['cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        'While' => ['cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        'DoWhile' => ['cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        'Switch' => ['cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        // `if` is php-parser's name for the middle arm, which an elvis does not have. Not `nthExpression(.., 1)`:
        // that is the middle arm of a full ternary and the *else* arm of an elvis, so the two would be
        // indistinguishable and the null test the rule opens with could never hold.
        'Conditional' => [
            'cond' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)'],
            'if' => [self::PHP_ONLY, 'expr', 'Support::conditionalThen($context, $node)'],
        ],
        'UnaryPrefix' => ['expr' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)']],
        // `$node->name` on a constant read is the node itself here. php-parser hangs a `Name` off the fetch;
        // mago's `ConstantAccess` *is* the name, and every question asked of it — does the codebase know it,
        // is it deprecated — is answered from the node by {@see Constants::constantMetadata()}, which has to
        // resolve it the way PHP does rather than compare text. So the field passes the node through instead
        // of navigating to a child that does not exist.
        'ConstantAccess' => ['name' => [self::PHP_ONLY, 'expr', '{base}']],
        'MethodCall' => [
            // `{base}` rather than `$node`, so a call the rule narrowed to with `instanceof` navigates itself
            // rather than the hook's node. For the hook's own node `{base}` renders as `$node`, which is what
            // makes the change byte-neutral for everything already emitted.
            'var' => ['node.object', 'expr', 'Support::nthExpression($context, {base}, 0)'],
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, {base})'],
        ],
        // The same three children in the same order, which the CST probe confirmed rather than assumed: there
        // is no extra node for the `?->` token to shift the positions.
        'NullSafeMethodCall' => [
            'var' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 0)'],
            'name' => [self::PHP_ONLY, 'name-selector', 'Support::selector($context, {base})'],
        ],
        'FunctionCall' => [
            'name' => ['node.function', 'name-expr', 'Support::nthExpression($context, $node, 0)'],
        ],
        'StaticMethodCall' => [
            'class' => ['node.class', 'name-expr', 'Support::classPart($context, {base})'],
            'name' => ['&node.method', 'name-selector', 'Support::selector($context, {base})'],
        ],
        // The same children a call has, which the CST probe confirmed rather than assumed: an `Expression`,
        // a `ClassLikeMemberSelector` and a `PartialArgumentList`, in that order.
        // A property access reads its name the way the `Expr` family does — through `namePart()`, which covers
        // both the written and the computed spelling — and its receiver as the first expression child.
        // The attribute hook's own node. `attributeName()` takes a part, and the hook hands over a `Node`, so
        // the conversion happens here rather than by widening a signature every emitted plugin already calls.
        'Attribute' => [
            'name' => [self::PHP_ONLY, 'bytes', 'Support::attributeName($context, Support::asPart($context, {base}))'],
            'args' => [self::PHP_ONLY, 'args', 'Support::argumentList($context, {base})'],
        ],
        'PropertyAccess' => [
            'var' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, {base}, 0)'],
            'name' => [self::PHP_ONLY, 'name-part', 'Support::namePart($context, {base})'],
        ],
        'StaticPropertyAccess' => [
            'class' => [self::PHP_ONLY, 'name-expr', 'Support::classPart($context, {base})'],
            'name' => [self::PHP_ONLY, 'name-part', 'Support::namePart($context, {base})'],
        ],
        'MethodPartialApplication' => [
            'var' => [self::PHP_ONLY, 'expr', 'Support::nthExpression($context, $node, 0)'],
            'name' => [self::PHP_ONLY, 'name-selector', 'Support::selector($context, {base})'],
        ],
        'StaticMethodPartialApplication' => [
            'class' => [self::PHP_ONLY, 'name-expr', 'Support::classPart($context, {base})'],
            'name' => [self::PHP_ONLY, 'name-selector', 'Support::selector($context, {base})'],
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
        // The attribute group as a hook node, for a rule registered on the group itself rather than reaching
        // one from a declaration. `KIND_FIELDS` answers the same field for a group *found* on a declaration;
        // this is the same navigation from the node the hook fired for.
        'AttributeList' => [
            'attrs' => [self::PHP_ONLY, 'attributes', 'Support::attributesOf(Support::asPart($context, {base}))'],
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
            // `$node->params`, which is the same list `getParams()` hands back — a rule writes whichever it
            // prefers and `NoValueObjectInServiceConstructorRule` writes the property.
            'params' => [self::PHP_ONLY, 'param-decls', 'Support::declaredParams($context, {base})'],
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
            // The `Identifier` child, not the attribute node. Mapped to the node, `textOf()` returned the
            // whole attribute -- `Marker('gone in 2.0')` -- which matched the bare `#[Attribute]` marker by
            // accident and nothing else.
            'name' => [self::PHP_ONLY, 'bytes', 'Support::attributeName($context, {base})'],
            // An attribute's arguments are an `ArgumentList` child in mago, the same shape a call carries,
            // so they reach the argument iterable through the same helper a call's arguments do.
            'args' => [self::PHP_ONLY, 'args', 'Support::argumentList($context, {base})'],
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
        // `foreach ($node->stmts as $stmt)` — the statements a declaration or closure body writes, top level
        // only. php-parser hangs them off `->stmts`, which resolves to a subtree here, and iterating one has
        // exactly this meaning: three rules write that loop and each then searches *inside* the statement it
        // was handed. Flattening would make the outer loop visit nodes the rule never sees.
        'subtree' => ['iter' => self::PHP_ONLY, 'item' => 'expr', 'phpIter' => 'Support::statementsOf($context, {rust})'],
        // The method declarations of a class-like body, one `method-decl` each.
        'method-members' => ['iter' => self::PHP_ONLY, 'item' => 'method-decl', 'phpIter' => '{rust}'],
        // The parameters a declaration writes, one `param-decl` each. The list was produced and asked for its
        // emptiness before it was iterable: `NoControllerMethodInjectionRule` walks a controller's methods and
        // then each method's parameters, and the second loop is the one that had no reading.
        'param-decls' => ['iter' => self::PHP_ONLY, 'item' => 'param-decl', 'phpIter' => '{rust}'],
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
        // Every literal string a type names, which is PHPStan's `getConstantStrings()`. A union of them names
        // more than one, and the rules that walk it act per element — so this is the list rather than the
        // single reduction `constantStringOf()` gives. The item stays a *type* rather than becoming text,
        // because the rules ask it for `->getValue()`.
        'constant-strings' => ['iter' => self::PHP_ONLY, 'item' => 'constant-string', 'phpIter' => '{rust}'],
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
        'ConstantTypeDeclarationCollector' => 'constants',
        'DeclareCollector' => 'declares',
        'PropertyTypeDeclarationCollector' => 'properties',
        'ReturnTypeDeclarationCollector' => 'returns',
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
     * to avoid needing. `none` is for a helper whose first argument is a call-site one.
     *
     * `types` are call-site positions forwarded as the *inferred type* of the expression rather than the
     * expression itself, and `flags` are container parameters the helper's answer depends on, which become
     * constructor parameters on the emitted plugin.
     *
     * @var array<string, array{helper: string, kind: string, takes: string, arguments: list<int>,
     *      types?: list<int>, flags?: list<string>, receiverType?: bool}>
     */
    public const array COLLABORATOR_CALLS = [
        // `kind: 'reports'` is the one entry that is not an answer. `AnnotationHelper::processDocComment()`
        // decides *and* builds the findings, and a rule returning that has nothing for this transpiler to
        // turn into guards or into a message — so the pass reports for itself, at the node the rule fired
        // for, under the identifier read out of the collaborator. Both rules using it keep their own
        // `TestCase` guard and their own choice of whose docblock is read.
        // The guard four of `phpstan-phpunit`'s assert rules open with. Its body assigns a type in each branch
        // of a decision tree rather than exiting from a chain of guards, so the inliner cannot take it — and
        // the four rules refused inside a method none of them wrote. `receiverType` says the emitted plugin
        // has to ask for it: requirements are opt-in, and without it the helper reads a null receiver and
        // answers false for every method call, silently.
        // `AttributeFinder::hasAttribute()` walks `attrGroups` two levels deep to reach each attribute's name,
        // which is the shape the `->attrGroups` mapping deliberately refuses to fake — metadata carries the
        // names flattened and resolved, so answering `->attrs` and `->name` from that list would be three
        // mappings pretending the tree has a shape it does not. The *question* maps exactly instead, and the
        // two Symfony rules that reach the finder through `SymfonyControllerAnalyzer` get it.
        'Symplify\PHPStanRules\NodeAnalyzer\AttributeFinder::hasAttribute' => [
            'helper' => 'Support::hasAttributeNamed',
            'kind' => 'bool',
            'takes' => 'context',
            'arguments' => [0, 1],
        ],
        // Four branches over PHPStan's type classes, which the vocabulary has no statements for — the
        // refusal it replaces named the shape rather than the cause, "early return from a helper that is not
        // a boolean literal". `types` because the rule hands it `$scope->getType($argValue)`, so the position
        // is an inferred type either way; the helper's own docblock carries what each branch was measured to
        // be, since PHPStan and mago disagree about which shape `Foo::class` and `class-string` produce.
        'Symplify\PHPStanRules\TypeAnalyzer\RectorAllowedAutoloadedTypeAnalyzer::isAllowedType' => [
            'helper' => 'RectorAutoloadedTypes::isAllowed',
            'kind' => 'bool',
            'takes' => 'context',
            'arguments' => [],
            'types' => [0],
        ],
        'PHPStan\Rules\PHPUnit\AssertRuleHelper::isMethodOrStaticCallOnAssert' => [
            'helper' => 'PhpUnitAsserts::isCallOnAssert',
            'kind' => 'bool',
            'takes' => 'context-node',
            'arguments' => [],
            'receiverType' => true,
        ],
        'PHPStan\Rules\PHPUnit\AnnotationHelper::processDocComment' => [
            'helper' => 'PhpUnitAnnotations::report',
            'kind' => 'reports',
            'takes' => 'context-node',
            'arguments' => [],
        ],

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

        // PHPStan's own rule-level machinery, ported into the runtime rather than translated: the call this
        // stands in for reaches `RuleLevelHelper::findTypeToCheck()`, which takes a criteria *closure*, and a
        // closure over PHPStan `Type` objects is not something this vocabulary can carry. `BooleanRuleHelper`
        // is one level out and hardcodes its callback, which is what makes it the smallest portable unit.
        //
        // `types` names argument positions passed as the *inferred type* of the expression rather than the
        // expression, since that is what the ported helper reads. `flags` names container parameters whose
        // value changes the answer: they become constructor parameters on the emitted plugin, never baked,
        // because hihaho runs `checkNullables: false` and Shopware `true` and one set cannot serve both.
        'PHPStan\Rules\BooleansInConditions\BooleanRuleHelper::passesAsBoolean' => [
            'helper' => 'RuleLevel::passesAsBoolean',
            'kind' => 'bool',
            'takes' => 'none',
            'arguments' => [],
            'types' => [1],
            'flags' => ['checkNullables', 'checkUnionTypes', 'checkThisOnly'],
        ],

        // Every rule in `phpstan-deprecation-rules` opens with this, so that deprecated code using
        // deprecated things does not warn. The helper is a loop over injected `DeprecatedScopeResolver`s and
        // the package ships exactly one, which asks whether the enclosing class, trait or function carries a
        // deprecation — three metadata reads. `takes: context-node` because those are questions about where
        // the node sits, and {@see Runtime\Deprecations} names what an extra resolver would cost.
        'PHPStan\Rules\Deprecations\DeprecatedScopeHelper::isScopeDeprecated' => [
            'helper' => 'Deprecations::scopeIsDeprecated',
            'kind' => 'bool',
            'takes' => 'context-node',
            'arguments' => [],
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
     * 13775; on mijntp (4372 files) 11428 against 11465. Both over-count — but "never under-counts" was a claim
     * about two corpora and it is false in general. `nikic/php-parser`, in this repository's own vendor
     * directory, measures -7, and the whole -7 is one file: `Internal/TokenPolyfill.php` declares
     * `TokenPolyfill` twice, once inside a version guard that returns. PHPStan counts what the file writes and
     * the port, reading metadata keyed by class name, counts neither body. The control
     * `conditionally-redeclared` pins it.
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
            'note' => 'Over-counts the original by up to 1.11% on the two Laravel consumers it was measured '
                . 'on: +81 of 13694 and +37 of 11428. The collector skips a method whose name an ancestor has, '
                . 'and PHPStan answers that from reflection extensions a Mago plugin cannot reproduce. It can '
                . 'also *under*-count, by a separate cause: a class declared twice in one file behind a version '
                . 'guard is counted by PHPStan and by neither body here, which is -7 on nikic/php-parser. '
                . 'Reproduce either with `php tests/Support/run-coverage-corpus.php <consumer-root>`.',
        ],
        'constants' => [
            'ceiling' => 0.0,
            'note' => 'Counted exactly on the two consumers it was measured on: 715 of 715 at 100.0 % typed '
                . 'and 636 of 636 at 98.4 % typed, agreeing on the percentage as well as the count. Three '
                . "things had to hold and each was measured first: a trait's constants are counted once for "
                . 'every class that uses it, and unlike its methods an override does not take them away; an '
                . "enum's cases are not constants the collector can see; and a grouped `const A = 1, B = 2;` "
                . 'is one declaration, found from the tree rather than by scanning the source, because one '
                . 'consumer writes a grouped constant whose default holds a brace. Reproduce with '
                . '`php tests/Support/run-coverage-corpus.php <consumer-root> --metric=constants`.',
        ],
        'returns' => [
            'ceiling' => 0.0,
            'note' => 'Counted exactly on the two Laravel consumers it was measured on: 18307 of 18307 and '
                . '8526 of 8526, agreeing on the percentage as well as the count. A zero ceiling is the '
                . 'measurement rather than an absence of one. Four things had to hold and each was measured '
                . 'first: a trait\'s methods are counted once for every class that reaches them and not once '
                . 'each, with a class reaching a trait through two traits counting twice; a class that '
                . 'declares the method itself does not reach the trait\'s, and a `@method` docblock takes no '
                . 'name away from it; magic methods are skipped by php-parser\'s list of seventeen names and '
                . 'not by mago\'s flag; and neither a `@method` entry nor an enum\'s `cases()`, `from()` and '
                . '`tryFrom()` is a declaration the collector can see. Reproduce with '
                . '`php tests/Support/run-coverage-corpus.php <consumer-root> --metric=returns`.',
        ],
        'properties' => [
            'ceiling' => 0.0,
            'note' => 'Counted exactly on the two Laravel consumers it was measured on: 866 of 866 and 1443 '
                . 'of 1443, agreeing on the percentage as well as the count. A zero ceiling is the '
                . 'measurement rather than an absence of one. Four things had to hold together and each was '
                . 'measured before it was relied on: a trait\'s properties are counted zero times, unlike '
                . 'its methods; a promoted property is not counted at all; a property is typed when it is '
                . 'written with a type, when a parent class declares it, or when its docblock mentions '
                . '`callable` or `resource`; and a declaration is taken where it is written, which '
                . '`nameLocation` says and `location` does not. Reproduce with '
                . '`php tests/Support/run-coverage-corpus.php <consumer-root> --metric=properties`.',
        ],
        'declares' => [
            'ceiling' => 0.0,
            'note' => 'Counted exactly on the two Laravel consumers it was measured on: 2932 of 2932 files '
                . 'and 1895 of 1895, agreeing on the percentage as well as the count. A zero ceiling is the '
                . 'measurement rather than an absence of one — the question is per file rather than per '
                . 'declaration, so the trait multiplicity and the reflection-extension lookup that bound the '
                . 'parameter metric have nothing to act on here. Reproduce with '
                . '`php tests/Support/run-coverage-corpus.php <consumer-root> --metric=declares`.',
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
        // All four class-like declarations, for the same reason `FunctionLike` registers all four of its
        // kinds: what a node type covers is a fact about the type. `ExplicitClassPrefixSuffixRule` narrows to
        // three of them and returns nothing for an enum, which is the rule declining rather than the plugin
        // not looking.
        ClassLike::class => ['Class', 'Interface', 'Trait', 'Enum'],
        // `[..]` and `array(..)` are one node to php-parser and two kinds to Mago, so registering the first
        // alone is silent on every array written the long way. Found on Shopware: `ForbiddenArrayMethodCallRule`
        // missed both `array($this, 'loadClass')` in a vendored `ClassLoader`, and reported neither.
        //
        // Probed rather than assumed, because a second kind is only useful if the body reads it the same way:
        // a `LegacyArray` carries the same `ArrayElement` children, `arrayElements()` returns the same two, and
        // `isArray()` already answered true for it.
        Array_::class => ['Array', 'LegacyArray'],
        // The written variable and the two computed ones. A rule asking `is_string($node->name)` is asking
        // which of these fired, so all three have to arrive or the question has one answer.
        Variable::class => ['DirectVariable', 'IndirectVariable', 'NestedVariable'],
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
