<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use LogicException;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\TriviaKind;

/**
 * The PHP target's support runtime, the counterpart of `support.rs`.
 *
 * Every recipe here was written against `probe.php` dumping the real tree, not against a guess at it.
 * Two things that only showed up that way: the operands of a call, an assignment and a class-constant
 * access are all wrapped in an `Expression`, so what Rust reads as a field is a grandchild here; and
 * `self`/`static` arrive as `Keyword` where a class name is an `Identifier`, though PHPStan treats all
 * three as a `Name`.
 */
final class Support
{
    // -----------------------------------------------------------------------
    // The codebase, where a rule used to ask PHPStan's ReflectionProvider
    // -----------------------------------------------------------------------

    /**
     * Whether a class-like of this name is known to the analysis.
     *
     * `ReflectionProvider::hasClass()` in the original. Mago answers it from the scanned codebase, so a class
     * it never scanned reads as absent — the same answer PHPStan gives for a class outside its autoloader.
     */
    /**
     * Whether the codebase knows a class-like of this name.
     *
     * Nullable and empty-guarded because the name can come from {@see self::resolvedName()} rather than from a
     * literal, and `classLikeExists('')` aborts the whole analysis with "Codebase metadata names cannot be
     * empty" — a worker crash, not a false answer.
     */
    public static function classExists(NodeAnalysisContext $context, ?string $name): bool
    {
        return Reflect::classExists($context, $name);
    }

    /**
     * Whether a class named by a *value* is abstract, which is `ReflectionProvider::getClass(..)->isAbstract()`.
     *
     * Kept apart from {@see declarationIsAbstract}, which reads the modifier on the declaration a hook fired
     * for. Here the name arrives as a string the plugin only has while it runs — the class a `createMock()`
     * argument names — so the question goes to the codebase instead.
     *
     * An unknown class answers false, the same way the rules that ask this guard with `hasClass()` first and
     * skip when it says no.
     */
    public static function namedClassIsAbstract(NodeAnalysisContext $context, ?string $name): bool
    {
        return Reflect::namedClassIsAbstract($context, $name);
    }

    /** The direct parent of a class named by a value. {@see Reflect::parentClassName} */
    public static function parentClassName(NodeAnalysisContext $context, ?string $name): ?string
    {
        return Reflect::parentClassName($context, $name);
    }

    /** Whether a class named by a value is one PHP itself ships. {@see Reflect::namedClassIsBuiltin} */
    /**
     * Whether the class around this node extends one that declares a constructor.
     *
     * The question `fast_has_parent_constructor($scope)` asks. See {@see Reflect::parentHasConstructor()}.
     */
    public static function parentHasConstructor(NodeAnalysisContext $context, Part|Node|null $node): bool
    {
        return Reflect::parentHasConstructor($context, $node);
    }

    public static function namedClassIsBuiltin(NodeAnalysisContext $context, ?string $name): bool
    {
        return Reflect::namedClassIsBuiltin($context, $name);
    }

    /** The file a class named by a value is declared in. {@see Reflect::namedClassFile} */
    public static function namedClassFile(NodeAnalysisContext $context, ?string $name): ?string
    {
        return Reflect::namedClassFile($context, $name);
    }

    /** Whether a class named by a value is an interface. {@see namedClassIsAbstract} says why this is separate. */
    public static function namedClassIsInterface(NodeAnalysisContext $context, ?string $name): bool
    {
        return Reflect::namedClassIsInterface($context, $name);
    }

    /**
     * The fully-qualified name a written name means, which is what `$scope->resolveName()` answers.
     *
     * Mago resolves a written name against the file's imports and namespace and hands back the result, so an
     * `Identifier` needs no work: `Thing` in `namespace Demo` comes back as `Demo\Thing`, an imported
     * `Imported` as `Other\Imported`, and `\Root\Absolute` with its leading slash removed. A name that
     * resolves to nothing declared still resolves — `hasClass()` is the separate question.
     *
     * Two spellings are not names to Mago and come back null, so they are answered here instead:
     *
     * - `self` and `static` are `Keyword` nodes, not identifiers, and PHPStan resolves both to the enclosing
     *   class. Probed: `getResolvedName()` returns null for each.
     * - `$name` in `new $name()` is a `Variable`, and null is right — PHPStan's rules guard on
     *   `instanceof Name` first, so they never ask.
     *
     * One gap, known rather than silent: `parent` is a `Keyword` too — probed, like the other two — and a node
     * hook has no metadata to resolve it through, so it comes back null where PHPStan would answer the parent
     * class. A rule reached through `new parent()` will disagree.
     */
    public static function resolvedName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Names::resolvedName($context, $subject);
    }

    /**
     * The class that actually declares a method, or null when neither the class nor the method is known.
     *
     * The distinction a rule cares about: a first-party class inheriting a vendor method should be judged on
     * where the method *comes from*, not on the receiver. `getDeclaringMethod()` answers exactly that.
     */
    public static function declaringClassOfMethod(NodeAnalysisContext $context, ?string $class, ?string $method): ?string
    {
        return Reflect::declaringClassOfMethod($context, $class, $method);
    }

    /**
     * The name of a parameter by position, or null when the method or the position is not known.
     *
     * What `ParametersAcceptorSelector::selectFromArgs(..)->getParameters()` is reached for in the original:
     * the *name* of the parameter an argument lands in, which is what a message about a positional argument
     * has to quote.
     */
    public static function parameterName(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): ?string
    {
        return Reflect::parameterName($context, $class, $method, $index);
    }

    /**
     * Whether the parameter at a position is variadic.
     *
     * A variadic parameter has no single argument position, so every rule in the corpus that names a
     * parameter skips one. Read from the metadata flags rather than inferred.
     */
    public static function parameterIsVariadic(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return Reflect::parameterIsVariadic($context, $class, $method, $index);
    }

    /**
     * Whether a method declares a parameter at a position, which is `$parameters[$i] ?? null` being non-null.
     *
     * A call may pass more arguments than the method declares — into a variadic, or wrongly — so a rule that
     * names the parameter an argument lands in has to ask this first.
     */
    public static function hasParameterAt(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return Reflect::hasParameterAt($context, $class, $method, $index);
    }

    /**
     * The declaring class's own name, walked from the class the method was asked of.
     *
     * `getDeclaringMethod()` hands back the method, not the class that declares it, so the class is found by
     * asking each ancestor in turn which one declares it directly.
     */
    /**
     * Whether a named class declares or inherits a method, which is `ClassReflection::hasMethod()`.
     *
     * `getDeclaringMethod()` answers for the whole hierarchy, which is what PHPStan's question means — a class
     * that inherits a method has it. Null-tolerant because the class name comes from
     * {@see self::resolvedName()}, which answers null for `parent` and for a variable class name.
     */
    public static function methodExists(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return Reflect::methodExists($context, $class, $method);
    }

    /**
     * The nth `Expression` child, unwrapped.
     *
     * A method call's receiver, a function call's name and both sides of an assignment are all the
     * inner node of an `Expression` child, distinguished only by position.
     */
    public static function nthExpression(NodeAnalysisContext $context, Part|Node|null $subject, int $index): ?Part
    {
        return Calls::nthExpression($context, $subject, $index);
    }

    /** An array element's key, or null when it is written without one. */
    public static function arrayElementKey(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::arrayElementKey($context, $subject);
    }

    /** A foreach's key variable, or null when it binds none. */
    public static function foreachKey(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Loops::foreachKey($context, $subject);
    }

    /** A foreach's value variable. */
    public static function foreachValue(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Loops::foreachValue($context, $subject);
    }

    /** The class side of a class-constant access or static call. */
    public static function classPart(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::classPart($context, $subject);
    }

    /** The middle arm of a ternary, or null for an elvis that has none. */
    public static function conditionalThen(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::conditionalThen($context, $subject);
    }

    /** The member selector of a method call: `->expects(..)` gives `expects`. */
    public static function selector(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::selector($context, $subject);
    }

    public static function argumentList(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::argumentList($context, $subject);
    }

    /** A navigated part's source text, for interpolating into a message. */
    public static function textOf(Part|string|null $subject): ?string
    {
        return Names::textOf($subject);
    }

    /**
     * Whether a declaration node is of the named kind.
     *
     * A plugin registered for classes, enums and interfaces asks this to skip the ones its rule excludes.
     * Mago gives each class-like its own node kind, so the question is the node's kind and nothing else.
     */
    /**
     * A function-like's name as `tomasvotruba/cognitive-complexity` spells it in its message.
     *
     * Four answers, one per kind the `FunctionLike` hook registers, reproducing
     * `FunctionLikeCognitiveComplexityRule::resolveFunctionName()`: a plain function is `name()`, a method is
     * `Class::name()` and falls back to `name()` where there is no enclosing class, and the two anonymous
     * kinds are the fixed strings the original uses. Those last two are unreachable through that rule, which
     * declines anything but a method or a function — reproduced anyway, because the port should not depend on
     * a caller's narrowing for its own correctness.
     *
     * Purely syntactic apart from the enclosing class name, which is why this is a helper rather than a
     * translated expression: the original branches four ways and builds a string in one of them.
     */
    public static function functionLikeName(NodeAnalysisContext $context, Part|Node|null $subject): string
    {
        return Members::functionLikeName($context, $subject);
    }

    /**
     * Whether the node is a method declaration, for a rule narrowing a function-like hook.
     *
     * A rule naming `FunctionLike` is handed every function-like there is and branches on the concrete one:
     * `instanceof ClassMethod` is this, `instanceof Function_` is the next one, and a closure or arrow
     * function matches neither — which is the rule declining, not the port going quiet.
     */
    public static function isMethodDeclaration(Part|Node|null $subject): bool
    {
        return Members::isMethodDeclaration($subject);
    }

    /** Whether the node is a plain function declaration. See {@see isMethodDeclaration()}. */
    public static function isFunctionDeclaration(Part|Node|null $subject): bool
    {
        return Members::isFunctionDeclaration($subject);
    }

    /** Whether the node is a constant declaration inside a class-like. */
    public static function isClassConstantDeclaration(Part|Node|null $subject): bool
    {
        return Members::isClassConstantDeclaration($subject);
    }

    /** Whether the node is a property declaration inside a class-like. */
    public static function isPropertyDeclaration(Part|Node|null $subject): bool
    {
        return Members::isPropertyDeclaration($subject);
    }

    public static function declarationKindIs(NodeAnalysisContext $context, Part|Node|null $subject, string $kind): bool
    {
        return Declares::declarationKindIs($context, $subject, $kind);
    }

    /**
     * The attributes on the declaration a hook fired for, by fully qualified name.
     *
     * `$node->attrGroups` in php-parser is two levels — groups, each holding attributes — and the rules that
     * read it only ever walk both to reach the names. Metadata carries them already flattened, and *resolved*:
     * measured, an imported `#[Entity]` comes back as `Doctrine\ORM\Mapping\Entity`, which is what
     * `$attr->name->toString()` gives a rule after PHPStan's own name resolution. Case survives too, unlike
     * every other name metadata holds — so a comparison against a written attribute name matches without
     * folding case, and folding it would be wider than the rule.
     *
     * Both hooks that ask: a class-like declaration reads its own attributes, and a method declaration reads
     * the ones on the method rather than on the class around it.
     *
     * @return list<string>
     */
    public static function attributeNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Attributes::attributeNames($context, $subject);
    }

    /** Whether a declaration carries the named attribute — `AttributeFinder::hasAttribute()`. */
    public static function hasAttributeNamed(NodeAnalysisContext $context, Part|Node|null $subject, string $name): bool
    {
        return Attributes::hasAttributeNamed($context, $subject, $name);
    }

    /**
     * The interfaces the enclosing class-like's declaration writes, which is `$classLike->implements`.
     *
     * `$directParentInterfaces`, not `$parentInterfaces`. Measured, because the two differ on exactly the case
     * these rules turn on: a class that implements nothing itself and extends one that implements `Target`
     * has an empty direct list and a populated transitive one. PHPStan reads the `implements` clause off the
     * declaration, so the direct list is the match and the transitive one would have made
     * `$classLike->implements !== []` true for a class whose declaration says nothing.
     *
     * Lowercased by the metadata, like every other name it holds.
     *
     * @return list<string>
     */
    public static function directInterfaceNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Declares::directInterfaceNames($context, $subject);
    }

    /**
     * Every trait the enclosing declaration picks up, as metadata spells them.
     *
     * `usedTraits` is already transitive and already inherited — probed on a trait using a trait, and on a
     * subclass using neither itself, and both listed both — so this is the field, not a walk over it. The names
     * come back lowercased, which is why anything comparing against one folds case.
     *
     * @return list<string>
     */
    public static function usedTraitNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Declares::usedTraitNames($context, $subject);
    }

    /**
     * Whether the enclosing declaration implements an interface, directly or through its hierarchy.
     *
     * `parentInterfaces` is the transitive set. Compared case-insensitively because metadata lowercases what it
     * holds while a configured or written name keeps the spelling its author used — which is the same folding
     * PHPStan gets by canonicalising both sides through reflection.
     */
    public static function classImplements(NodeAnalysisContext $context, Part|Node|null $subject, ?string $interface): bool
    {
        return Declares::classImplements($context, $subject, $interface);
    }

    /**
     * Whether a list of names holds one, folding case.
     *
     * The list comes from metadata, which lowercases; the name comes from configuration or from the analysed
     * source, which does not. A strict comparison between the two is the silent-miss shape.
     *
     * @param list<string> $names
     */
    public static function namesContain(array $names, ?string $name): bool
    {
        return Text::namesContain($names, $name);
    }

    /**
     * Whether the enclosing declaration has a method of this name, anywhere in its hierarchy.
     *
     * Answered through the same declaring-class lookup a rule reading that class uses, so the two cannot
     * disagree: a name this says exists is a name that lookup can attribute.
     */
    public static function classHasMethod(NodeAnalysisContext $context, Part|Node|null $subject, ?string $method): bool
    {
        return Declares::classHasMethod($context, $subject, $method);
    }

    /**
     * The values a list holds more than once, each named once.
     *
     * Built on `array_count_values()` rather than around it, so the key coercion is the same: that function
     * turns a numeric-string value into an integer key, and a rule that goes on to print the duplicates prints
     * whatever it produced. Reimplementing the count would have quietly changed that.
     *
     * @param list<string> $values
     *
     * @return list<int|string>
     */
    public static function repeatedValues(array $values): array
    {
        return Text::repeatedValues($values);
    }

    /**
     * A configured map with keys that differ only in case collapsed to one entry, the last winning.
     *
     * This stands in for a rewriting the rule did and the plugin does not. The original built its map keyed by
     * each name's *declared* spelling, so two configured keys naming the same trait in different cases became
     * one entry and the later assignment won. Carrying the configured map as written kept both, and a
     * case-insensitive match then found both — reporting the same finding twice.
     *
     * Only the keys collapse. The values are compared case-insensitively wherever they are used, so folding
     * them would change nothing but the spelling a message prints.
     *
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    public static function foldedKeys(array $map): array
    {
        return Text::foldedKeys($map);
    }

    /**
     * The name of the function or method the node sits in, or null outside one.
     *
     * What `$scope->getFunctionName()` gives a rule, which is the *named* function a node sits in however many
     * closures deep. {@see Declares::enclosingFunctionName()} cites the two lines of `MutatingScope` that say
     * so; this facade carried the opposite claim for as long as the walk did.
     */
    public static function enclosingFunctionName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Declares::enclosingFunctionName($context, $subject);
    }

    /**
     * Whether an inferred type is callable, which is `$type->isCallable()->yes()`.
     *
     * Mago models a type as its atomic parts, and a callable is one of them. A closure object is a named object
     * rather than a `CallableType`, so it is matched by name — that is the shape `Closure::fromCallable()` and a
     * closure literal both produce.
     */
    public static function typeIsCallable(NodeAnalysisContext $context, ?Type $type): bool
    {
        return Types::typeIsCallable($context, $type);
    }

    /**
     * Whether an inferred type is a union, which is what `$type instanceof UnionType` asks.
     *
     * Mago models a type as its atomic parts, so a union is simply a type with more than one of them. That
     * matches PHPStan on the nullable case too: `A|null` is a `UnionType` there and two atomic types here.
     */
    public static function typeIsUnion(?Type $type): bool
    {
        return Types::typeIsUnion($type);
    }

    /**
     * Whether the node the hook fired for is of one kind.
     *
     * A plugin registered for several kinds asks this where the rule branched on the concrete one. Mago gives
     * each its own kind, so the question is the node's kind and nothing else.
     */
    public static function nodeKindIs(NodeAnalysisContext $context, Part|Node|null $subject, string $kind): bool
    {
        $node = Tree::node($subject);

        return $node instanceof Node && $node->kind->value === $kind;
    }

    /**
     * The part a node names its member by, whatever kind of access or call it is.
     *
     * php-parser puts this on `->name` for six different classes and a rule asking "is this name written or
     * computed" asks it of all of them. Mago spells it three ways, probed rather than assumed:
     *
     *   ClassConstantAccess    `Holder::FIXED`   ClassLikeConstantSelector
     *   StaticPropertyAccess   `Holder::$prop`   Variable
     *   MethodCall             `$o->m()`         ClassLikeMemberSelector
     *   StaticMethodCall       `Holder::m()`     ClassLikeMemberSelector
     *   PropertyAccess         `$o->inst`        ClassLikeMemberSelector
     *   FunctionCall           `target()`        the first Expression child, there being no selector
     */
    public static function namePart(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::namePart($context, $subject);
    }

    /**
     * Whether a node names its member dynamically — computed at runtime rather than written out.
     *
     * The question `! $node->name instanceof Expr` asks, inverted: php-parser gives an `Identifier` for a
     * written name and an expression for anything else. Mago has no such split, so the answer comes from the
     * spelling: a selector holding a variable or a braced expression is dynamic, a bare word is not.
     */
    public static function hasDynamicName(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Calls::hasDynamicName($context, $subject);
    }

    /**
     * Whether a name part is written out rather than computed.
     *
     * Structural, not textual: a static property's *written* name is `$prop`, so a leading `$` proves nothing.
     * Probed instead — a written name holds a `LocalIdentifier`, a written static property a `DirectVariable`,
     * a written function name an `Identifier`. Everything else is computed: `NestedVariable` for `Holder::$$n`,
     * `Variable` for `$o->$n`, `ClassLikeMemberExpressionSelector` for either braced form.
     *
     * This is what php-parser answers with the type of `->name`: an `Identifier` where it is written, an
     * expression where it is not.
     */
    public static function isWrittenName(?Part $part): bool
    {
        return Calls::isWrittenName($part);
    }

    /** The enclosing declaration's extends clause, joined as PHPStan prints it. */
    public static function extendsText(NodeAnalysisContext $context, Part|Node|null $subject): string
    {
        return Inheritance::extendsText($context, $subject);
    }

    /** Whether a unary prefix expression's operator is the one written. {@see Calls::unaryOperatorIs} */
    public static function unaryOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return Operators::unaryOperatorIs($context, $subject, $operator);
    }

    /** Whether a postfix expression's operator is the one written — `$x++` rather than `++$x`. */
    public static function postfixOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return Operators::postfixOperatorIs($context, $subject, $operator);
    }

    /** Whether a binary expression's operator is the one written, which Mago keeps in a child node. */
    public static function binaryOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return Operators::binaryOperatorIs($context, $subject, $operator);
    }

    /**
     * The name a node *writes* — a variable's own name, or a name or identifier's text, or null.
     *
     * The question `NamingHelper::getName()` asks. Null for anything else, which is what the rules reading it
     * test for. See {@see Names::writtenName()}.
     */
    public static function writtenName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Names::writtenName($context, $subject);
    }

    /**
     * The name php-parser hands a rule for a node, after PHPStan has resolved the file's names.
     *
     * A resolved name for an ordinary one, the keyword itself for `self`, `static` and `parent`, and the
     * written name for anything that is not a name. See {@see Names::nameAfterResolution()}.
     */
    public static function nameAfterResolution(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Names::nameAfterResolution($context, $subject);
    }

    /** Whether a navigated part is `__DIR__`, which php-parser models as its own node class. */
    public static function isDirConstant(?Part $part): bool
    {
        return Names::isDirConstant($part);
    }

    /**
     * A quoted string's value with its quotes removed, which is php-parser's `String_->value`.
     *
     * Null-tolerant for the same reason `constantNameText()` is: reading the value of something that is not a
     * string literal has no answer, and the rule's own `instanceof String_` guard is what makes sure it never
     * asks. Escapes are left as written — no rule in the corpus compares against a value that carries one, and
     * unescaping without a case that needs it would be inventing a behaviour.
     */
    public static function literalStringValue(NodeAnalysisContext $context, ?Part $part): ?string
    {
        return Names::literalStringValue($context, $part);
    }

    /** Whether a navigated part is a quoted string, which is php-parser's `Scalar\String_`. */
    public static function isLiteralString(NodeAnalysisContext $context, ?Part $part): bool
    {
        return Names::isLiteralString($context, $part);
    }

    public static function isName(?Part $part): bool
    {
        return Names::isName($part);
    }

    public static function nameEquals(?Part $part, string $literal): bool
    {
        return Names::nameEquals($part, $literal);
    }

    /** The selector's own name, which is case sensitive in PHP as method names are compared. */
    public static function selectorIs(?Part $part, string $literal): bool
    {
        return Calls::selectorIs($part, $literal);
    }

    public static function selectorIsIdentifier(?Part $part): bool
    {
        return Names::selectorIsIdentifier($part);
    }

    /**
     * Whether a class name is one of PHP's own — `self`, `parent` or `static`.
     *
     * Those three arrive as `Keyword` where a written class name is an `Identifier`, which is the
     * distinction php-parser spells `Name::isSpecialClassName()`. Rules use it as a filter: a name that
     * resolves relative to the current class cannot be compared against a written one.
     */
    public static function isSpecialClassName(?Part $part): bool
    {
        return Names::isSpecialClassName($part);
    }

    /**
     * Whether a class name is written relative to the current namespace, as `namespace\Foo`.
     *
     * Answered from the name's own text, because that prefix is what makes it relative and Mago resolves the
     * name before a hook sees it. Compared case insensitively, as PHP treats the keyword.
     */
    public static function isRelativeName(?Part $part): bool
    {
        return Names::isRelativeName($part);
    }

    public static function isVariable(?Part $part): bool
    {
        return Names::isVariable($part);
    }

    /** `$foo` gives `foo`; anything else, including `$$foo`, gives null. */
    public static function directVariableName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Names::directVariableName($context, $subject);
    }

    public static function isMethodCall(?Part $part): bool
    {
        return Calls::isMethodCall($part);
    }

    public static function isStaticCall(?Part $part): bool
    {
        return Calls::isStaticCall($part);
    }

    /**
     * A call in an expression position, unwrapped from its category node.
     *
     * An argument holds `Call` whose child is the concrete `MethodCall` or `FunctionCall`, the same
     * wrapping the tree uses for `Expression` and `Access`. Only a probe showed it: a nested
     * `$this->any()` arrives as `Call`, so a kind check against `MethodCall` silently never matched and
     * the rule reported nothing.
     */
    /**
     * Whether the part is a plain function call, which is php-parser's `Expr\FuncCall`.
     *
     * Through the same `Call` unwrapping as {@see isMethodCall} and {@see isStaticCall}: Mago wraps every call
     * in a `Call` node whose first child carries the concrete kind, and asking the wrapper answers no for all
     * three.
     */
    public static function isFunctionCall(?Part $part): bool
    {
        return Calls::isFunctionCall($part);
    }

    /** Whether the part is a `Foo::BAR` access, which is php-parser's `Expr\ClassConstFetch`. */
    public static function isClassConstantAccess(?Part $part): bool
    {
        return Calls::isClassConstantAccess($part);
    }

    /**
     * Whether the part is an array literal.
     *
     * A callable written as `[$this, 'method']` is an array literal here, which is what the rules that
     * ask this are looking for.
     */
    public static function isArray(?Part $part): bool
    {
        return Calls::isArray($part);
    }

    public static function isPropertyFetch(?Part $part): bool
    {
        return Calls::isPropertyFetch($part);
    }

    public static function isArrayDimFetch(?Part $part): bool
    {
        return Calls::isArrayDimFetch($part);
    }

    public static function isInt(?Part $part): bool
    {
        return $part instanceof Part && preg_match('/^-?\d+$/', $part->text) === 1;
    }

    public static function intLiteralValue(?Part $part): ?int
    {
        return self::isInt($part) ? (int) $part->text : null;
    }

    /**
     * An integer literal compared against a bound, absent meaning false.
     *
     * Rust writes this as `int_literal_value(x).is_some_and(|v| v >= 1)`. PHP has no `is_some_and`, and
     * emitting the null check plus the comparison would call the helper twice, so the operator comes in
     * as an argument. Unknown operators throw rather than defaulting, because a silently wrong
     * comparison is the kind of thing that makes a generated rule untrustworthy.
     */
    public static function intCompares(?Part $part, string $operator, int $bound): bool
    {
        $value = self::intLiteralValue($part);
        if ($value === null) {
            return false;
        }

        return match ($operator) {
            '>=' => $value >= $bound,
            '>' => $value > $bound,
            '<=' => $value <= $bound,
            '<' => $value < $bound,
            '===', '==' => $value === $bound,
            default => throw new LogicException("unsupported integer comparison {$operator}"),
        };
    }

    /** @return list<Part> the positional arguments, in source order */
    public static function arguments(?Part $list): array
    {
        return Calls::arguments($list);
    }

    public static function argCount(?Part $list): int
    {
        return Calls::argCount($list);
    }

    /**
     * The nth positional argument, unwrapped to the expression it holds.
     *
     * The tree is `Argument > PositionalArgument > Expression > <the value>`, so stopping at the first
     * child yields the `PositionalArgument`. Every level has the same *text*, which is why the
     * text-based predicates (`isInt`, `nameEquals`) worked at the wrong depth while the kind-based ones
     * (`isArray`, `isVariable`) silently never matched.
     */
    public static function positionalArgAt(?Part $list, int $index): ?Part
    {
        return Calls::positionalArgAt($list, $index);
    }

    /** The nth argument, still wrapped, so the questions about *how* it was written can be asked of it. */
    public static function argumentAt(?Part $list, int $index): ?Part
    {
        return Calls::argumentAt($list, $index);
    }

    /**
     * The expression an argument holds, unwrapped through the layers Mago's tree puts around it.
     *
     * The tree is `Argument > PositionalArgument > Expression > <the value>`, so stopping at the first
     * child yields the `PositionalArgument`. Every level has the same *text*, which is why the
     * text-based predicates (`isInt`, `nameEquals`) worked at the wrong depth while the kind-based ones
     * (`isArray`, `isVariable`) silently never matched.
     *
     * A named argument nests one level deeper — `NamedArgument > LocalIdentifier, Expression` — so its name
     * child is skipped rather than mistaken for the value.
     */
    public static function argumentValue(?Part $argument): ?Part
    {
        return Calls::argumentValue($argument);
    }

    /**
     * Whether an argument is written with a name — `enabled: true`.
     *
     * Mago spells the distinction as the node kind under `Argument`: `NamedArgument` carries a
     * `LocalIdentifier` before its expression, `PositionalArgument` carries none. Read from the kind rather
     * than from the text, because a positional argument's text can contain a colon of its own.
     */
    public static function argumentIsNamed(?Part $argument): bool
    {
        return Calls::argumentIsNamed($argument);
    }

    /**
     * Whether an expression is a bare constant name — what php-parser calls a `ConstFetch`.
     *
     * Mago splits the one PHP concept across two node kinds, probed rather than assumed: `true`, `false` and
     * `null` are `Literal` nodes holding a `Keyword`, while any other bare name — `FOO`, `PHP_INT_MAX` — is a
     * `ConstantAccess`. A `Literal` holding a `LiteralInteger` or a `LiteralString` is neither, so the keyword
     * child is what has to be checked rather than the `Literal` kind alone.
     */
    public static function isConstantName(?Part $part): bool
    {
        return Names::isConstantName($part);
    }

    /**
     * The name a bare constant name is written with, or null when the expression is not one.
     *
     * php-parser puts a `Name` on `ConstFetch->name`, so a rule reads `->name->toLowerString()` after guarding
     * on `instanceof ConstFetch`. Null-tolerant for the same reason the guard exists: reading the name of
     * something that is not a constant name has no answer, and null makes every comparison against it false.
     */
    public static function constantNameText(?Part $part): ?string
    {
        return Names::constantNameText($part);
    }

    /** `->toLowerString()` on a name, which rules use so a comparison ignores how the name was written. */
    public static function lowerBytes(?string $text): ?string
    {
        return Text::lowerBytes($text);
    }

    /**
     * Every class a type names, when the type is *certainly* an object — PHPStan's `getObjectClassNames()`.
     *
     * The list rather than the single-class reduction `soleObjectClass()` makes: a rule that loops these is
     * asking about each member of a union, and answering with one would go quiet on exactly the receivers the
     * loop exists for. Names as written — metadata lowercases them, and a rule comparing against a namespace
     * prefix needs the case.
     *
     * **A union with a non-object member names nothing**, and that is the whole difference between this and
     * {@see soleObjectClassIgnoringNull()}. `?Request` is not certainly a request, so a rule asking "is the
     * receiver a Request" gets no for it — which is what the original does, measured on two Nova actions
     * holding `protected ?ActionRequest $request = null` where the port reported and PHPStan did not.
     *
     * Both answers are needed and neither is a bug: the positional-flag check strips null deliberately,
     * because it asks what methods the receiver has rather than what it certainly is. The helper a rule gets
     * follows the accessor the rule used.
     *
     * @return list<string>
     */
    public static function objectClasses(?Type $type): array
    {
        return Types::objectClasses($type);
    }

    /**
     * The classes a type names, with a null atomic skipped.
     *
     * @return list<string>
     */
    public static function objectClassesIgnoringNull(?Type $type): array
    {
        return Types::objectClassesIgnoringNull($type);
    }

    /**
     * Whether a class *is* another, or descends from it — PHPStan's `isSuperTypeOf` between two object types.
     *
     * Case-insensitive because `getClassAncestors()` answers in lowercase, the same trap that silenced an
     * earlier port. A class the codebase does not know descends from nothing, which is the safe answer: the
     * rule asking is looking for a reason to report.
     */
    public static function classDescendsFrom(NodeAnalysisContext $context, ?string $class, string $parent): bool
    {
        return Reflect::classDescendsFrom($context, $class, $parent);
    }

    /**
     * The last segment of a written name — php-parser's `Name::getLast()`.
     *
     * A leading backslash is part of how the name was written, not part of the segment, so `\Acme\request`
     * and `request` both answer `request`.
     */
    public static function lastNameSegment(?string $name): ?string
    {
        return Names::lastNameSegment($name);
    }

    /**
     * Whether the codebase knows a function by this name — PHPStan's `hasFunction()`.
     *
     * A name written in a namespace resolves the way PHP resolves it: `Acme\request()` falls back to the
     * global `request()` when the namespace declares none, so both are tried.
     */
    public static function functionExists(NodeAnalysisContext $context, ?string $name): bool
    {
        return self::functionName($context, $name) !== null;
    }

    /**
     * The name the codebase knows a function under, as declared, or null when it knows none.
     *
     * What `$reflectionProvider->getFunction($name, $scope)->getName()` gives a rule: the *resolved* name, so
     * a rule comparing it against `request` sees through a namespaced call that falls back to the global one.
     */
    public static function functionName(NodeAnalysisContext $context, ?string $name): ?string
    {
        return Names::functionName($context, $name);
    }

    /** The other half of `lowerBytes()`, for a rule that folds the other way. */
    public static function upperBytes(?string $text): ?string
    {
        return Text::upperBytes($text);
    }

    /**
     * Whether an argument is spread — `...$rest`.
     *
     * Probed rather than assumed: a spread argument is still a `PositionalArgument`, with no separate kind and
     * no ellipsis child to find, so the leading `...` in its text is the only thing that distinguishes it.
     *
     * That makes one case a known false negative rather than a silent one: an argument written with a block
     * comment before its spread puts that comment where the `...` would be, so this answers no. A rule reached
     * that way reports where PHPStan would not.
     */
    public static function argumentIsUnpacked(?Part $argument): bool
    {
        return Calls::argumentIsUnpacked($argument);
    }

    /** `$this->foo(..)`, the receiver being `$this` rather than any expression. */
    public static function isThisMethodCall(NodeAnalysisContext $context, Part|Node|null $subject, string $method): bool
    {
        return Calls::isThisMethodCall($context, $subject, $method);
    }

    /**
     * Whether a lookup table the plugin built holds a key, where the key may not have resolved.
     *
     * `isset($table[$key])` with a null key is not an error in PHP — it reads `$table['']` — so the emitted
     * form worked and analysing the generated plugins is what flagged it. Worth a helper rather than a cast:
     * a rule's table is keyed by names it wrote, and `''` is a name it *could* write, so coercing an
     * unresolved key into it would answer yes to a question nobody asked.
     *
     * @param array<string, mixed> $table
     */
    public static function lookupHas(array $table, ?string $key): bool
    {
        return Text::lookupHas($table, $key);
    }

    /**
     * `array_any()` is PHP 8.4, and the generated rules should run on 8.1.
     *
     * Generic, because the body is: the emitter hands it a list of names from a configured list and a list of
     * `Part`s from a declaration's items, and annotating one of those made every emission of the other a type
     * error. Nothing noticed until the generated plugins were analysed — the gate that checks a helper *exists*
     * cannot see what it is handed.
     *
     * @template TItem
     *
     * @param list<TItem> $items
     * @param callable(TItem): bool $predicate
     */
    public static function anyOf(array $items, callable $predicate): bool
    {
        return Text::anyOf($items, $predicate);
    }

    /**
     * @param list<string> $items
     * @param callable(string): bool $predicate
     */
    public static function allOf(array $items, callable $predicate): bool
    {
        return Text::allOf($items, $predicate);
    }

    /**
     * Narrows to a method call, the counterpart of Rust's `as_method_call`.
     *
     * On this target the node kind already answers it, so this only unwraps and checks; it exists so a
     * generated binding reads the same as the Rust one.
     */
    public static function asMethodCall(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::asMethodCall($context, $subject);
    }

    /**
     * The object a property access reads from — php-parser's `PropertyFetch->var`.
     *
     * Probed: `$this->current` is `Access → PropertyAccess → [Expression($this), ClassLikeMemberSelector]`, so
     * the target is the access's first expression, one category node down. Null for anything that is not a
     * property access, which is what makes the rule's own `instanceof PropertyFetch` guard meaningful.
     */
    public static function propertyFetchTarget(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::propertyFetchTarget($context, $subject);
    }

    /**
     * The array an index read reads from — php-parser's `ArrayDimFetch->var`.
     *
     * `$arr['k']` is `ArrayAccess → [Expression($arr), Expression('k')]`, with no `Access` category node above
     * it, which is why this is not the same walk as a property access.
     */
    public static function dimFetchTarget(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::dimFetchTarget($context, $subject);
    }

    /** Whether the enclosing class-like declaration has an extends clause at all. */
    public static function hasExtends(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Inheritance::hasExtends($context, $subject);
    }

    public static function extendsIs(NodeAnalysisContext $context, Part|Node|null $subject, string $name): bool
    {
        return Inheritance::extendsIs($context, $subject, $name);
    }

    /**
     * Whether the extends clause names one of these. {@see Inheritance::extendsIsOneOf}
     *
     * @param list<string> $names
     */
    public static function extendsIsOneOf(NodeAnalysisContext $context, Part|Node|null $subject, array $names): bool
    {
        return Inheritance::extendsIsOneOf($context, $subject, $names);
    }

    public static function bytesContain(?string $haystack, string $needle): bool
    {
        return Text::bytesContain($haystack, $needle);
    }

    public static function bytesEndWith(?string $haystack, string $needle): bool
    {
        return Text::bytesEndWith($haystack, $needle);
    }

    public static function bytesStartWith(?string $haystack, string $needle): bool
    {
        return Text::bytesStartWith($haystack, $needle);
    }

    /**
     * Whether a string value is one of a set.
     *
     * The counterpart of {@see selectorIsOneOf} for a value that is already a string rather than a node: a
     * helper's string parameter, or the enclosing namespace. Compared case sensitively, because the sets it
     * is asked about — function names in a constant table — are written the way PHP compares them.
     *
     * @param list<string> $values
     */
    public static function bytesIsOneOf(?string $subject, array $values): bool
    {
        return Text::bytesIsOneOf($subject, $values);
    }

    /**
     * An inferred type as PHPStan's `describe(VerbosityLevel::typeOnly())` writes it.
     *
     * {@see Describe} for why this renders from the atomics rather than from `Type::__toString()`, and for the
     * measurement that decided it: 9.38 % of the types at these positions render differently.
     */
    public static function describeType(?Type $type): ?string
    {
        return Describe::type($type);
    }

    /**
     * Whether a pattern matches, which is `Strings::match(..) !== null` and `preg_match(..) === 1`.
     *
     * Nette's helper hands back the capture array or null; with two arguments and its defaults that is
     * `preg_match()`'s own answer, so the two spellings reduce to one question here. A null subject cannot
     * match anything, which is what the guards in front of it already assume.
     */
    public static function matchesPattern(?string $subject, string $pattern): bool
    {
        return Text::matchesPattern($subject, $pattern);
    }

    /**
     * Whether a written class name resolves to one of a set of class names.
     *
     * The counterpart of {@see bytesIsOneOf} for a list the rule wrote as `::class` fetches. PHPStan compares
     * such a list against `Name::toString()`, and php-parser has already rewritten that name through the
     * file's imports — so `new Name(..)` under `use PhpParser\Node\Name;` reads back fully qualified. Mago
     * keeps the name as written, which is why the resolved name is asked for instead of the text.
     *
     * Leading `\` and case are handled as {@see nameEquals} handles them, for the reason recorded there:
     * php-parser does not keep the separator, and a comparison that does is silent on the fully-qualified
     * spelling.
     *
     * @param list<string> $names
     */
    public static function resolvedNameIsOneOf(NodeAnalysisContext $context, Part|Node|null $subject, array $names): bool
    {
        return Names::resolvedNameIsOneOf($context, $subject, $names);
    }

    /** @param list<string> $names */
    public static function selectorIsOneOf(?Part $part, array $names): bool
    {
        return Names::selectorIsOneOf($part, $names);
    }

    /** Whether every part of a type is a boolean, which is `Type::isBoolean()->yes()`. {@see Types::typeIsBoolean} */
    public static function typeIsBoolean(?Type $type): bool
    {
        return Types::typeIsBoolean($type);
    }

    /** Whether the inferred type is a single named object rather than a union, scalar or mixed. */
    public static function typeIsNamedObject(?Type $type): bool
    {
        return Types::typeIsNamedObject($type);
    }

    /**
     * Whether the inferred type is, or descends from, `$name`.
     *
     * Only a single named object answers this. A union receiver is not one class, and PHPStan's rules
     * that ask this question require exactly one object class reflection too, so refusing to answer for
     * a union matches the original rather than guessing at its intent.
     */
    public static function typeIsInstanceOf(NodeAnalysisContext $context, ?Type $type, string $name): bool
    {
        return Types::typeIsInstanceOf($context, $type, $name);
    }

    /**
     * Whether an inferred type has a method, which is `$type->hasMethod($m)->yes()` in PHPStan.
     *
     * `methodExists()`, not `getMethod()`. The latter answers about methods the class *declares*, so it returns
     * null for every inherited one — measured on `Rector\Config\RectorConfig::make()`, which comes from the
     * container it extends: `getMethod` NULL, `getDeclaringMethod` found, `methodExists` yes, hierarchy
     * complete, four ancestors. PHPStan's question is hierarchy-inclusive, so this was answering no about any
     * method a class did not write itself, and `ForbiddenArrayMethodCallRule` stayed silent on
     * `[$rectorConfig, 'make']` where the original reports.
     *
     * The `getMethod()` call left in {@see attributeNames} is correct for the opposite reason: a declaration
     * hook fires on a method this class-like writes, so its own attributes are what that reads.
     */
    public static function typeHasMethod(NodeAnalysisContext $context, ?Type $type, string $method): bool
    {
        return Types::typeHasMethod($context, $type, $method);
    }

    /**
     * The one class an inferred type names, or null when it does not name exactly one.
     *
     * `$type->getObjectClassReflections()` with a `count() === 1` gate, which is how a rule asks "a single
     * concrete receiver". A union of two classes is not one class, and a rule that named a parameter against
     * one arbitrary member would suggest a name the other does not have.
     *
     * Cased as written — `NamedObjectType->name` keeps `Demo\Widget`, unlike `ClassLikeMetadata->name`, which
     * arrives lowercased. Measured, not read.
     */
    public static function soleObjectClass(?Type $type): ?string
    {
        return Types::soleObjectClass($type);
    }

    /**
     * The same question asked after dropping `null` from the type, which is `TypeCombinator::removeNull()`.
     *
     * Load-bearing for a nullsafe call, and it was measured rather than assumed: a `?Widget` receiver arrives
     * as two atomics, a `NamedObjectType` and a `SimpleAtomicType` of kind `Null`. The strict helper answers
     * null for that, so a port of a rule that removeNulls first would have gone silent on exactly the receivers
     * `?->` exists for — no error, just nothing reported.
     *
     * Separate from {@see soleObjectClass()} rather than replacing it: a rule that does *not* removeNull is
     * asking a narrower question, and answering the wider one for it would make the port wider than the rule.
     */
    public static function soleObjectClassIgnoringNull(?Type $type): ?string
    {
        return Types::soleObjectClassIgnoringNull($type);
    }

    /**
     * The items of a constant declaration: `const A = 1, B = 2;` has two.
     *
     * @return list<Part>
     */
    public static function constantItems(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Constants::constantItems($context, $subject);
    }

    /**
     * The constant *declarations* a class-like holds — `const A = 1, B = 2;` is one of them.
     *
     * Probed: a declaration sits under a `ClassLikeMember` wrapper rather than directly under the class, the
     * same way methods and properties do. `getConstants()` in php-parser answers with the statements, which is
     * what a rule then reads the items out of.
     *
     * @return list<Part>
     */
    public static function constantDeclarations(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Constants::constantDeclarations($context, $subject);
    }

    /**
     * A constant item's value, as the node it is written as.
     *
     * Probed: an item holds its name as a `LocalIdentifier` and its value wrapped in an `Expression`, which is
     * unwrapped here the way {@see nthExpression} unwraps it — so what comes back is the value a rule asks the
     * type of, not the wrapper.
     */
    public static function constantItemValue(NodeAnalysisContext $context, ?Part $item): ?Part
    {
        return Constants::constantItemValue($context, $item);
    }

    /** A constant item's name, without its value. */
    public static function constantItemName(?Part $item): ?string
    {
        return Constants::constantItemName($item);
    }

    /**
     * A property declaration's type hint, or null when it has none.
     *
     * The hook is handed a `Property`, which wraps a `PlainProperty` (or a hooked or promoted variant),
     * so the hint can be one level down. Found by descending rather than assumed, since an untyped
     * property has no `Hint` child at all and must answer null rather than something empty.
     */
    public static function propertyHint(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Members::propertyHint($context, $subject);
    }

    public static function hintIsUnion(?Part $hint): bool
    {
        return Hints::hintIsUnion($hint);
    }

    public static function hintIsIntersection(?Part $hint): bool
    {
        return Hints::hintIsIntersection($hint);
    }

    /**
     * The members of a union or intersection hint, or the hint itself when it is neither.
     *
     * @return list<Part>
     */
    public static function hintParts(?Part $hint): array
    {
        return Hints::hintParts($hint);
    }

    /**
     * A hint that is a class-like *name*, which is what php-parser's `$param->type instanceof Name` asks.
     *
     * Not "a hint that is present and not a union" — that was the earlier reading, and it is wider than the
     * question: php-parser gives an `Identifier` for a builtin, so a rule asking `instanceof Name` is
     * distinguishing a class from `int`. Nothing emitted depended on the wider version.
     *
     * Mago's discriminator, probed across ten written forms:
     *
     * | written                          | `Hint` child      | resolves |
     * |----------------------------------|-------------------|----------|
     * | `Widget`, `\Root\Deep`, an import | `Identifier`      | to a FQN |
     * | `int`, `iterable`, `mixed`       | `LocalIdentifier` | no       |
     * | `array`, `callable`              | `Keyword`         | no       |
     * | `self`, `static`, `parent`       | `Keyword`         | no       |
     * | `?Widget`                        | `NullableHint`    | no       |
     * | `string\|int`                    | `UnionHint`       | no       |
     *
     * So an `Identifier` child is a class-like name. The `Keyword` row splits, because php-parser does: `self`,
     * `static` and `parent` are `Name` there while `array` and `callable` are `Identifier`, and a vocabulary that
     * answered no for `self` would be *narrower* than the rule — the direction that makes a port silently miss.
     */
    public static function hintIsName(?Part $hint): bool
    {
        return Hints::hintIsName($hint);
    }

    /**
     * The parameters a function-like declares, in order.
     *
     * `FunctionLikeParameterList` holds one `FunctionLikeParameter` each, and a closure with no parameters still
     * has the list — so an empty list and a missing one are the same answer, which is what `getParams()` gives.
     *
     * @return list<Part>
     */
    public static function declaredParams(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Members::declaredParams($context, $subject);
    }

    /** The nth declared parameter, or null when the declaration has no such position. */
    public static function declaredParamAt(NodeAnalysisContext $context, Part|Node|null $subject, int $index): ?Part
    {
        return Members::declaredParamAt($context, $subject, $index);
    }

    /** The written type of a declared parameter, or null when it has none. */
    public static function declaredParamHint(?Part $parameter): ?Part
    {
        return Members::declaredParamHint($parameter);
    }

    /**
     * A string split on a pattern, dropping the empty pieces. {@see Text::splitByPattern}
     *
     * @return list<string>
     */
    public static function splitByPattern(?string $subject, string $pattern): array
    {
        return Text::splitByPattern($subject, $pattern);
    }

    /** Whether a name is written entirely in upper case, as a constant convention check. */
    public static function isUppercase(?string $value): bool
    {
        return Text::isUppercase($value);
    }

    /** A hint's written name, resolved through the file's imports where possible. */
    public static function hintName(NodeAnalysisContext $context, ?Part $hint): ?string
    {
        return Hints::hintName($context, $hint);
    }

    public static function hintNameIs(NodeAnalysisContext $context, ?Part $hint, string $name): bool
    {
        return Hints::hintNameIs($context, $hint, $name);
    }

    /**
     * The method declarations of a class-like body, in source order.
     *
     * Walked rather than read off one level, because a class-like's members sit inside its body node. This is
     * php-parser's `$classLike->getMethods()`, so it is the methods *written here* — not the ones a trait brings
     * in, and not the inherited ones a reflection lookup would add.
     *
     * @return list<Part>
     */
    public static function classMethods(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Bodies::classMethods($context, $subject);
    }

    /**
     * Every member a class-like body writes, in source order — php-parser's `$classLike->stmts`.
     *
     * @return list<Part>
     */
    public static function classMembers(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Bodies::classMembers($context, $subject);
    }

    /**
     * The statements a node holds, which is php-parser's `$node->stmts`.
     *
     * Load-bearing that this is the *body* and not the node: `findInstanceOf($node->stmts, Foreach_::class)`
     * inside a foreach must not find the foreach it started from, or every count is one too high.
     *
     * A declaration with no body answers null, because php-parser's `$node->stmts` is null there and three
     * rules in the corpus open by testing exactly that. Mago spells the absence one level down, which was
     * measured rather than assumed: an abstract method and an interface method both carry a `MethodBody`
     * child whose text is `";"` and whose only child is `MethodAbstractBody`. Reading the wrapper alone
     * answered "has a body" for both, and the fires-gate caught it on a pair written for the question.
     */
    public static function bodyOf(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Members::bodyOf($context, $subject);
    }

    /**
     * The statements a declaration or closure body holds, one `Statement` each.
     *
     * @return list<Part>
     */
    public static function statementsOf(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Members::statementsOf($context, $subject);
    }

    /**
     * Every node of the given kinds anywhere below this one, which is php-parser's `NodeFinder::findInstanceOf()`.
     *
     * Recurses blindly, including into nested closures and functions, because php-parser does: a rule counting
     * nested `foreach` statements counts one written inside a closure too. Stopping at a function boundary would
     * be the port deciding something the rule does not.
     *
     * **The starting node counts.** php-parser's traverser visits the nodes it is given, so
     * `findInstanceOf($node, Foreach_::class)` inside a foreach finds that foreach. Which makes
     * `findInstanceOf($node->stmts, ..)` — what the rules actually write — the version that excludes it, and puts
     * the exclusion in the `->stmts` navigation where the rule put it. Skipping the root here instead would give
     * the same answer for every rule in the corpus and the wrong one for the first rule that passes a node.
     *
     * @param list<string> $kinds
     *
     * @return list<Part>
     */
    public static function findKind(NodeAnalysisContext $context, Part|Node|null $within, array $kinds): array
    {
        return Tree::findKind($context, $within, $kinds);
    }

    /** Whether a class-like declaration is written `abstract`, which is a modifier on it. */
    public static function declarationIsAbstract(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Declares::declarationIsAbstract($context, $subject);
    }

    /** Whether the class-like *around* this node is abstract — `$scope->getClassReflection()->isAbstract()`. */
    public static function enclosingClassIsAbstract(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Reflect::enclosingClassIsAbstract($context, $subject);
    }

    /**
     * The written name of a class-like or method declaration, short and unqualified.
     *
     * php-parser's `$node->name->toString()` on a declaration gives the name as written, not the namespaced one —
     * `Something` rather than `App\Something` — which is what a rule testing a prefix or suffix compares.
     */
    public static function declarationName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Members::declarationName($context, $subject);
    }

    /**
     * One method declaration of a class-like body, by name, or null when it declares none.
     *
     * php-parser's `ClassLike::getMethod()`, which a rule uses to reach a method it learned the name of at
     * analysis time — a data provider named in a docblock. Case insensitive, as PHP method names are.
     */
    public static function methodNamed(NodeAnalysisContext $context, Part|Node|null $classLike, ?string $name): ?Part
    {
        return Bodies::methodNamed($context, $classLike, $name);
    }

    /** A method declaration's own name. */
    public static function methodName(?Part $method): ?string
    {
        return Members::methodName($method);
    }

    /**
     * Whether a method is magic, which php-parser answers from a fixed list of seventeen names.
     *
     * Not "starts with `__`": that would catch `__myHelper`, which php-parser does not, and the direction of
     * that error is a port reporting where the rule stays silent.
     */
    public static function methodIsMagic(?Part $method): bool
    {
        return Members::methodIsMagic($method);
    }

    /**
     * Whether a method declaration carries a modifier.
     *
     * `public` is the default in PHP, so php-parser's `isPublic()` is true for a method with no visibility
     * modifier at all — which is why absence has to mean public rather than unknown.
     */
    public static function methodIsPublic(?Part $method): bool
    {
        return Reflect::methodIsPublic($method);
    }

    /**
     * A node as a navigable part, for the helpers that read a declaration's own children.
     *
     * The hook's own node arrives as a `Node`, while a member loop yields a `Part`. The declaration predicates
     * take a `Part`, and widening them would change the signature every emitted plugin already calls — so the
     * conversion happens at the call site instead.
     */
    public static function asPart(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return Calls::asPart($context, $subject);
    }

    /**
     * One named group of a match, or null when the pattern did not match or the group caught nothing.
     *
     * An empty capture reads as null here. `preg_match()` fills an unmatched optional group with `''`, and a
     * rule's `isset($matches['x'])` cannot tell the two apart — so treating `''` as "not caught" matches what
     * the rule means. No pattern in the corpus has an optional group that can match empty.
     */
    public static function captured(string $pattern, ?string $subject, string $group): ?string
    {
        return Text::captured($pattern, $subject, $group);
    }

    /** Whether a method declaration is written `private`, which php-parser answers from its modifiers. */
    public static function methodIsPrivate(?Part $method): bool
    {
        return Reflect::methodIsPrivate($method);
    }

    /** Whether a method declaration is written `protected`. */
    public static function methodIsProtected(?Part $method): bool
    {
        return Reflect::methodIsProtected($method);
    }

    /**
     * Whether a class-like member is written `protected`, wherever that member keeps its modifiers.
     *
     * A property keeps them one level down, so this is not {@see methodIsProtected()}.
     */
    public static function memberIsProtected(?Part $member): bool
    {
        return Reflect::memberIsProtected($member);
    }

    /** Whether the codebase's method is public. A method that is not found is not public. */
    public static function reflectedMethodIsPublic(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return Members::reflectedMethodIsPublic($context, $class, $method);
    }

    /** Whether the codebase's method is private. */
    public static function reflectedMethodIsPrivate(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return Members::reflectedMethodIsPrivate($context, $class, $method);
    }

    public static function methodIsStatic(?Part $method): bool
    {
        return Reflect::methodIsStatic($method);
    }

    /**
     * The attribute groups a declaration carries, one per `#[..]` written on it.
     *
     * @return list<Part>
     */
    public static function attributeGroups(?Part $declaration): array
    {
        return Attributes::attributeGroups($declaration);
    }

    /**
     * The attributes inside one group: `#[A, B]` is one group holding two.
     *
     * @return list<Part>
     */
    public static function attributesOf(?Part $group): array
    {
        return Attributes::attributesOf($group);
    }

    /**
     * Where a finding points: the given part, or the node the hook fired for when there is no part.
     *
     * A rule that loops a class-like's members reports each finding on its own member, which PHPStan spells
     * `->line($member->getLine())`. Null-tolerant so a navigation that found nothing anchors at the hook's node
     * rather than crashing the worker — a finding on the wrong line is a defect, a dead worker is worse.
     */
    public static function anchor(NodeAnalysisContext $context, Part|Node|null $subject): Span
    {
        $node = Tree::node($subject);

        return $node instanceof Node ? $node->span : $context->node->span;
    }

    /**
     * Where a finding about the file as a whole belongs: the first statement, not the top of the file.
     *
     * PHPStan's `FileNode` is synthetic and takes its position from the first statement it holds, so a rule
     * reporting on the file lands on `declare(strict_types=1);` in a file that opens with one. Mago's `Program`
     * starts at byte zero, and its first child statement is the `<?php` tag — which php-parser does not model
     * as a statement at all. Skipping the opening tag is what makes the two anchors the same line, and the
     * example pair for `ForbiddenMultipleClassLikeInOneFileRule` is what caught the three-line difference.
     */
    public static function fileAnchor(NodeAnalysisContext $context, Part|Node|null $program): Span
    {
        $node = Tree::node($program);
        if (! $node instanceof Node) {
            return $context->node->span;
        }

        foreach ($context->source->getChildren($node) as $statement) {
            foreach ($context->source->getChildren($statement) as $inner) {
                if (in_array($inner->kind, [NodeKind::OpeningTag, NodeKind::FullOpeningTag, NodeKind::ShortOpeningTag], true)) {
                    continue 2;
                }
            }

            return $statement->span;
        }

        return $node->span;
    }

    /**
     * The lines of a docblock that carry a tag, which is what `getTagsByName()` hands a rule.
     *
     * Matched as a tag rather than as a substring: `@enum` must not be found in `@enumerate`, and a rule
     * asking for one tag and getting another is the kind of wrong answer that reads as right. The tag has to
     * be followed by whitespace, a parenthesis, or the end of the line.
     *
     * @return list<string>
     */
    public static function docblockTags(?string $docblock, string $tag): array
    {
        return Text::docblockTags($docblock, $tag);
    }

    /**
     * The inferred type of any sub-expression, which is what `$scope->getType($expr)` gives a rule.
     *
     * Available to a plain node hook, not only to an after-file one — probed, because two rounds of this
     * repository's own documentation said otherwise. Emitted plugins declare
     * `FileAnalysisRequirement::ExpressionTypes` when they ask this, and that is where the requirement stands;
     * what has actually been measured is narrower. For a sub-expression *of the plugin's own target node*, the
     * types arrive whether or not the requirement is declared: removing it from the type-rendering probe left
     * every row unchanged. Nothing has been measured about a position outside the target subtree, so the
     * requirement stays declared rather than being dropped on one node kind's evidence.
     *
     * Null when the node is not an expression: a class member has no type, and a rule asking one of those gets
     * nothing rather than a wrong answer.
     */
    public static function expressionType(NodeAnalysisContext $context, Part|Node|null $subject): ?Type
    {
        $node = Tree::node($subject);

        return $node instanceof Node ? $context->analysis->getExpressionType($node) : null;
    }

    /**
     * The value behind a type that is one literal string, or null when it is not one.
     *
     * PHPStan spells this `ConstantStringType` and reads it with `->getValue()`. Mago's `Type` *renders* as
     * plain `string` either way — the literal is in the structure, on the scalar's refinement — so reading the
     * rendering would answer "not a constant" for every string in the corpus. Probed, not read.
     *
     * The same gap between the rendering and the structure runs through every shape, not only strings:
     * `DescribesTypesLikePhpstanTest` measures four more, and
     * `Type::$atomicTypes` carries all four.
     */
    public static function constantStringOf(?Type $type): ?string
    {
        return Types::constantStringOf($type);
    }

    /** Whether every part of a type is a literal string — PHPStan's `Type::isLiteralString()->yes()`. */
    public static function typeIsLiteralString(?Type $type): bool
    {
        return Types::typeIsLiteralString($type);
    }

    /**
     * The same question asked of the *expression*, so a class constant answers with its own initialiser.
     *
     * `$scope->getType()` on a constant fetch answers the value PHPStan reads off the declaration, and a
     * widening `@var` docblock does not take that away. Mago's inferred type honours the docblock, so the
     * fallback in {@see ConstantStrings} reads the declaration instead. Every other shape is answered by the
     * inferred type exactly as before.
     */
    public static function constantStringAt(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return ConstantStrings::at($context, $subject);
    }

    /**
     * Every literal string a type names, which is PHPStan's `Type::getConstantStrings()`.
     *
     * The plural, and the singular above is now the first of these. A union of literal strings names more
     * than one, and the rules that reach here `foreach` the list and act per element — so reducing to one
     * would decide something the rule does not. Filtering the atomics is what the plural needs; the singular
     * reduces afterwards, where reducing is what the caller asked for.
     *
     * @return list<string>
     */
    public static function constantStringsOf(?Type $type): array
    {
        return Types::constantStringsOf($type);
    }

    /**
     * The elements of an array literal, in order.
     *
     * The `ArrayElement` category node rather than the `ValueArrayElement` beneath it: both carry the same text
     * and the same inferred type, and a kind predicate written against the inner one matches nothing among the
     * direct children — the trap this project has hit before with `Expression` and `Call`.
     *
     * @return list<Part>
     */
    public static function arrayElements(NodeAnalysisContext $context, Part|Node|null $array): array
    {
        return Calls::arrayElements($context, $array);
    }

    /** Two written names compared the way PHP compares them: case-insensitively, and null matching nothing. */
    public static function nameIs(?string $written, string $name): bool
    {
        return Text::nameIs($written, $name);
    }

    /** The fully-qualified name an attribute names, which is what `$attr->name->toString()` gives a rule. */
    public static function attributeName(NodeAnalysisContext $context, ?Part $attribute): ?string
    {
        return Attributes::attributeName($context, $attribute);
    }

    /**
     * The docblock immediately above a declaration, or null when it has none.
     *
     * Mago hands comments back as file-level trivia carrying a span and a kind but no text, so the text comes
     * from the source and the *association* is arithmetic this owns. The rule mirrors php-parser's own
     * attachment: the last docblock that ends before the declaration starts and begins after whatever precedes
     * it, so a method with no docblock cannot inherit its neighbour's. A declaration's span includes its
     * attribute list, which is what makes `\/** doc *\/ #[Attr] public function` associate correctly.
     */
    public static function docblockText(NodeAnalysisContext $context, Part|Node|null $declaration): ?string
    {
        // Either shape: a member loop yields a `Part`, a hook hands over its own `Node`. Typed to `Part` alone
        // this threw a TypeError inside the worker, which surfaces as an orchestrator error naming the
        // *protocol* rather than the argument.
        $node = Tree::node($declaration);
        if (! $node instanceof Node) {
            return null;
        }

        $start = $node->span->start;

        foreach ($context->source->getTrivia() as $trivia) {
            if ($trivia->kind !== TriviaKind::DocBlockComment || $trivia->span->end > $start) {
                continue;
            }

            // Adjacent means nothing but whitespace in between, which is php-parser's own rule: a comment
            // attaches to the token that follows it. Anything else — another member, an attribute belonging to
            // something else — and this docblock is not this declaration's, so a member without one cannot
            // inherit its neighbour's.
            if ($trivia->span->end < $start && trim($context->source->getText(new Span($trivia->span->end, $start))) !== '') {
                continue;
            }

            return $context->source->getText($trivia->span);
        }

        return null;
    }

    /**
     * The property declarations of a class-like body.
     *
     * @return list<Part>
     */
    public static function classProperties(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Bodies::classProperties($context, $subject);
    }

    public static function fileEndsWith(NodeAnalysisContext $context, string $suffix): bool
    {
        return str_ends_with($context->source->path, $suffix);
    }

    /**
     * The other two questions a rule asks about the analysed file's path.
     *
     * The transpiler has always mapped `str_starts_with($scope->getFile(), ..)` and `str_contains(..)` onto these
     * names, and neither existed — so a rule that asked either emitted a plugin that loaded and then killed the
     * worker with "Call to undefined method". No shipped rule had asked yet, which is the only reason it went
     * unnoticed, and `TranspilesToPhpTest` could not see it because that gate walks the fixture rules and none of
     * them asked either. It now walks the whole corpus.
     */
    public static function fileStartsWith(NodeAnalysisContext $context, string $prefix): bool
    {
        return str_starts_with($context->source->path, $prefix);
    }

    /**
     * The directory the analysed file sits in, absolute, which is what `dirname($scope->getFile())` gives.
     *
     * Absolute matters because the path reaches the reader: `StringFileAbsolutePathExistsRule` puts it in its
     * message, and Mago's `source->path` is workspace-relative where PHPStan's `getFile()` is absolute. The two
     * agreed on the line and differed on the text until this resolved, which is why the gate compares messages
     * rather than lines.
     */
    public static function fileDirectory(NodeAnalysisContext $context): string
    {
        $path = $context->source->path;
        $resolved = realpath($path);

        return dirname($resolved === false ? $path : $resolved);
    }

    /**
     * Whether a path a rule built exists on disk.
     *
     * A plugin is PHP, so it can ask the filesystem the same question the rule asks. Null-tolerant because the
     * path is built from a string the rule read off a node, and a node that held no string yields none.
     */
    public static function pathExists(?string $path): bool
    {
        return Text::pathExists($path);
    }

    public static function fileContains(NodeAnalysisContext $context, string $needle): bool
    {
        return str_contains($context->source->path, $needle);
    }

    /** @param list<string> $suffixes */
    public static function fileEndsWithAny(NodeAnalysisContext $context, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($context->source->path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The namespace the analysed file declares, or null when it declares none.
     *
     * `$scope->getNamespace()` has no direct equivalent in the SDK. The file's own text does: `SourceFile`
     * carries `contents` under the `SourceText` requirement, and a PHP file declares at most one namespace
     * before any declaration. Read from the text rather than from the node tree because the answer is a
     * property of the file, not of the target — a rule asks it from an expression deep inside a method.
     *
     * The resolved-name route considered instead — taking an unqualified call's resolved name and dropping
     * its last segment — fails for an already-qualified name, which is why it is not used.
     */
    public static function enclosingNamespace(NodeAnalysisContext $context): ?string
    {
        return Names::enclosingNamespace($context);
    }

    /**
     * The items of a property declaration: `protected $a = 1, $b = 2;` has two.
     *
     * The tree, read from a probe rather than assumed: `Property` wraps a `PlainProperty`, which holds one
     * `PropertyItem` per declared name. php-parser calls the same list `$node->props`.
     *
     * @return list<Part>
     */
    public static function propertyItems(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        return Members::propertyItems($context, $subject);
    }

    /**
     * A property item's default value, or null when it declares none.
     *
     * The tree, from a probe rather than assumed: an initialised item holds a `PropertyConcreteItem` whose
     * children are the `DirectVariable` and an `Expression` wrapping the value; an uninitialised one holds a
     * `PropertyAbstractItem` with only the variable. The `Expression` wrapper is unwrapped, the same way
     * {@see nthExpression} unwraps it, so what comes back is the value node a rule asks `instanceof` of.
     */
    public static function propertyItemDefault(?Part $item): ?Part
    {
        return Members::propertyItemDefault($item);
    }

    /**
     * A property item's name, without the `$`.
     *
     * `$with = ['author']` gives `with`. The name is a `DirectVariable` under a `PropertyConcreteItem` for an
     * initialised property, or directly under the item for an uninitialised one, so both are searched.
     */
    public static function propertyItemName(?Part $item): ?string
    {
        return Members::propertyItemName($item);
    }

    /** The enclosing class-like declaration's name, or null at top level. */
    public static function enclosingClassName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Declares::enclosingClassName($context, $subject);
    }

    /** Whether the nearest class-like around a node is a trait. {@see Reflect::isInTrait} */
    public static function isInTrait(NodeAnalysisContext $context, Part|Node|null $node): bool
    {
        return Reflect::isInTrait($context, $node);
    }

    public static function isInClass(NodeAnalysisContext $context, Part|Node|null $node): bool
    {
        return Declares::isInClass($context, $node);
    }

    /**
     * Whether the enclosing class is, or descends from, `$name`.
     *
     * `getClassAncestors()` answers in lowercase, which silently disabled every parent exclusion in an
     * earlier port until a probe printed the values, so the comparison is case insensitive here.
     */
    public static function enclosingClassIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        return Declares::enclosingClassIs($context, $node, $name);
    }

    /**
     * The classes the enclosing declaration extends, nearest first, as written.
     *
     * `ClassLikeMetadata->parentClasses` rather than `Codebase::getClassAncestors()`: that one folds in
     * interfaces and traits, and a rule walking parents to find an overridden method means parents. Names
     * arrive lowercased from metadata, which is fine for looking a class up again and wrong for printing.
     *
     * @return list<string>
     */
    public static function parentClassNames(NodeAnalysisContext $context, Part|Node|null $node): array
    {
        return Inheritance::parentClassNames($context, $node);
    }

    /** The class-declaration hook's `metadata_is`, which asks the same question. */
    public static function metadataIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        return Declares::metadataIs($context, $node, $name);
    }

    /** Whether the codebase knows the constant this node reads. PHPStan's `hasConstant()`. */
    public static function constantExists(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Constants::constantExists($context, $subject);
    }

    /** Whether the constant this node reads carries a deprecation. */
    public static function constantIsDeprecated(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return Constants::constantIsDeprecated($context, $subject);
    }

    /** The constant's name as the codebase holds it, for a message that interpolates it. */
    public static function constantName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return Constants::constantName($context, $subject);
    }

    /**
     * A finding's message, with the trait's using classes named when the subject is declared in one.
     *
     * Returns the message unchanged everywhere else, which is everything but a trait-declared member, so
     * this wraps every report and changes almost none of them.
     */
    public static function viaTraitUsers(NodeAnalysisContext $context, Part|Node|null $node, string $message): string
    {
        $users = Declares::satisfyingUsers($context, $node);

        return $users === [] ? $message : $message . ' (via ' . implode(', ', $users) . ')';
    }
}
