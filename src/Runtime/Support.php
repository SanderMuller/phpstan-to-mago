<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use LogicException;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;
use Mago\Sdk\Syntax\SourceFile;

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
        if ($name === null || $name === '') {
            return false;
        }

        return $context->codebase->classLikeExists($name);
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
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        if ($node->kind === NodeKind::Keyword) {
            $keyword = strtolower(trim($context->source->getText($node)));

            return $keyword === 'self' || $keyword === 'static'
                ? self::enclosingClassName($context, $node)
                : null;
        }

        $resolved = $context->source->getResolvedName($node);

        return $resolved instanceof ResolvedName && $resolved->name !== '' ? $resolved->name : null;
    }

    /**
     * The class that actually declares a method, or null when neither the class nor the method is known.
     *
     * The distinction a rule cares about: a first-party class inheriting a vendor method should be judged on
     * where the method *comes from*, not on the receiver. `getDeclaringMethod()` answers exactly that.
     */
    public static function declaringClassOfMethod(NodeAnalysisContext $context, ?string $class, ?string $method): ?string
    {
        if ($class === null || $method === null) {
            return null;
        }

        $declared = $context->codebase->getDeclaringMethod($class, $method);

        return $declared instanceof FunctionLikeMetadata ? self::declaringClassName($context, $class, $method) : null;
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
        $parameter = self::parameterAt($context, $class, $method, $index);
        if (! $parameter instanceof ParameterMetadata) {
            return null;
        }

        // `ParameterMetadata->name` keeps the sigil — `$urgent` — where PHPStan's `getName()` drops it.
        // Measured against the real rule: the port landed on the right line with `($urgent: ...)` in a message
        // that has to read `(urgent: ...)`, because it is telling the reader what to type.
        return ltrim($parameter->name, '$');
    }

    /**
     * Whether the parameter at a position is variadic.
     *
     * A variadic parameter has no single argument position, so every rule in the corpus that names a
     * parameter skips one. Read from the metadata flags rather than inferred.
     */
    public static function parameterIsVariadic(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return self::parameterAt($context, $class, $method, $index)?->flags->contains(MetadataFlags::VARIADIC) === true;
    }

    /**
     * Whether a method declares a parameter at a position, which is `$parameters[$i] ?? null` being non-null.
     *
     * A call may pass more arguments than the method declares — into a variadic, or wrongly — so a rule that
     * names the parameter an argument lands in has to ask this first.
     */
    public static function hasParameterAt(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return self::parameterAt($context, $class, $method, $index) instanceof ParameterMetadata;
    }

    private static function parameterAt(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): ?ParameterMetadata
    {
        if ($class === null || $method === null || $index < 0) {
            return null;
        }

        $declared = $context->codebase->getDeclaringMethod($class, $method);
        if (! $declared instanceof FunctionLikeMetadata) {
            return null;
        }

        $parameter = $declared->parameters[$index] ?? null;

        return $parameter instanceof ParameterMetadata ? $parameter : null;
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
        if ($class === null || $method === null || $class === '' || $method === '') {
            return false;
        }

        return $context->codebase->getDeclaringMethod($class, $method) instanceof FunctionLikeMetadata;
    }

    /**
     * The name of the class that declares a method, spelled as it was written.
     *
     * `ClassLikeMetadata->name` is **lowercased** — measured, not read: `Examples\Flags\Sender` comes back as
     * `examples\flags\sender`. A rule comparing a declaring class against a namespace prefix therefore matched
     * nothing, and the whole rule went silent while every other guard passed. `originalName` keeps the case.
     */
    private static function declaringClassName(NodeAnalysisContext $context, string $class, string $method): ?string
    {
        foreach ([$class, ...$context->codebase->getClassAncestors($class)] as $candidate) {
            $own = $context->codebase->getClass($candidate);
            if ($own instanceof ClassLikeMetadata && in_array($method, $own->methods, true)) {
                return $own->originalName;
            }
        }

        return null;
    }

    /** Declaration kinds, by backed value: see the note in {@see enclosingClassName}. */
    private const array CLASS_LIKE_KINDS = ['Class', 'Interface', 'Trait', 'Enum'];

    /** Kinds that stand in for `instanceof PhpParser\Node\Name`. */
    private const array NAME_KINDS = [NodeKind::Identifier, NodeKind::Keyword, NodeKind::LocalIdentifier];

    private static function part(NodeAnalysisContext $context, Node $node): Part
    {
        return new Part($node->kind, trim($context->source->getText($node)), $node, $context->source);
    }

    /**
     * Navigation takes either a raw node or an already-navigated part.
     *
     * A narrowing binding hands back a Part, and the rule then reads a field off it, so the same
     * helpers have to accept both without the generated code caring which it holds.
     */
    private static function node(Part|Node|null $subject): ?Node
    {
        return $subject instanceof Part ? $subject->node : $subject;
    }

    /**
     * The nth `Expression` child, unwrapped.
     *
     * A method call's receiver, a function call's name and both sides of an assignment are all the
     * inner node of an `Expression` child, distinguished only by position.
     */
    public static function nthExpression(NodeAnalysisContext $context, Part|Node|null $subject, int $index): ?Part
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $seen = 0;
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::Expression) {
                continue;
            }

            if ($seen++ !== $index) {
                continue;
            }

            $inner = $context->source->getChildren($child)[0] ?? null;

            return self::part($context, $inner ?? $child);
        }

        return null;
    }

    /** The class side of a class-constant access or static call. */
    public static function classPart(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return self::nthExpression($context, $subject, 0);
    }

    /** The member selector of a method call: `->expects(..)` gives `expects`. */
    public static function selector(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ClassLikeMemberSelector) {
                $inner = $context->source->getChildren($child)[0] ?? null;

                return self::part($context, $inner ?? $child);
            }
        }

        return null;
    }

    public static function argumentList(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ArgumentList) {
                return self::part($context, $child);
            }
        }

        return null;
    }

    /** A navigated part's source text, for interpolating into a message. */
    public static function textOf(Part|string|null $subject): ?string
    {
        if ($subject === null || is_string($subject)) {
            return $subject;
        }

        return $subject->text;
    }

    /** The enclosing declaration's extends clause, joined as PHPStan prints it. */
    public static function extendsText(NodeAnalysisContext $context, Part|Node|null $subject): string
    {
        return implode(', ', self::extendsNames($context, $subject));
    }

    public static function isName(?Part $part): bool
    {
        return $part instanceof Part && in_array($part->kind, self::NAME_KINDS, true);
    }

    public static function nameEquals(?Part $part, string $literal): bool
    {
        return $part instanceof Part && strcasecmp($part->text, $literal) === 0;
    }

    /** The selector's own name, which is case sensitive in PHP as method names are compared. */
    public static function selectorIs(?Part $part, string $literal): bool
    {
        return $part instanceof Part && $part->text === $literal;
    }

    public static function selectorIsIdentifier(?Part $part): bool
    {
        return $part instanceof Part && in_array($part->kind, self::NAME_KINDS, true);
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
        return $part instanceof Part && $part->kind === NodeKind::Keyword;
    }

    /**
     * Whether a class name is written relative to the current namespace, as `namespace\Foo`.
     *
     * Answered from the name's own text, because that prefix is what makes it relative and Mago resolves the
     * name before a hook sees it. Compared case insensitively, as PHP treats the keyword.
     */
    public static function isRelativeName(?Part $part): bool
    {
        return $part instanceof Part && str_starts_with(strtolower($part->text), 'namespace\\');
    }

    public static function isVariable(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::Variable;
    }

    /** `$foo` gives `foo`; anything else, including `$$foo`, gives null. */
    public static function directVariableName(?Part $part): ?string
    {
        if (! $part instanceof Part || $part->kind !== NodeKind::Variable) {
            return null;
        }

        return str_starts_with($part->text, '$') ? substr($part->text, 1) : null;
    }

    public static function isMethodCall(?Part $part): bool
    {
        return self::concreteCall($part)?->kind === NodeKind::MethodCall;
    }

    /**
     * A call in an expression position, unwrapped from its category node.
     *
     * An argument holds `Call` whose child is the concrete `MethodCall` or `FunctionCall`, the same
     * wrapping the tree uses for `Expression` and `Access`. Only a probe showed it: a nested
     * `$this->any()` arrives as `Call`, so a kind check against `MethodCall` silently never matched and
     * the rule reported nothing.
     */
    private static function concreteCall(?Part $part): ?Part
    {
        if (! $part instanceof Part) {
            return null;
        }

        return $part->kind === NodeKind::Call ? $part->firstChild() : $part;
    }

    /**
     * Whether the part is an array literal.
     *
     * A callable written as `[$this, 'method']` is an array literal here, which is what the rules that
     * ask this are looking for.
     */
    public static function isArray(?Part $part): bool
    {
        // `Array` and `LegacyArray` are the short and `array(..)` spellings. `NodeKind::Array` is fine
        // even though `array` is a reserved word: only `::class` is special-cased by the parser, which
        // is worth stating because the `NodeKind::Class` trap looks like it should apply here and does not.
        return $part instanceof Part
            && ($part->kind === NodeKind::Array || $part->kind === NodeKind::LegacyArray);
    }

    public static function isPropertyFetch(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::Access;
    }

    public static function isArrayDimFetch(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::ArrayAccess;
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
        if (! $list instanceof Part) {
            return [];
        }

        $out = [];
        foreach ($list->children() as $child) {
            if ($child->kind === NodeKind::Argument) {
                $out[] = $child;
            }
        }

        return $out;
    }

    public static function argCount(?Part $list): int
    {
        return count(self::arguments($list));
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
        return self::argumentValue(self::argumentAt($list, $index));
    }

    /** The nth argument, still wrapped, so the questions about *how* it was written can be asked of it. */
    public static function argumentAt(?Part $list, int $index): ?Part
    {
        return self::arguments($list)[$index] ?? null;
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
        if (! $argument instanceof Part) {
            return null;
        }

        $inner = $argument;
        foreach ([[NodeKind::PositionalArgument, NodeKind::NamedArgument], [NodeKind::Expression]] as $layer) {
            $child = null;
            foreach ($inner->children() as $candidate) {
                if (in_array($candidate->kind, $layer, true)) {
                    $child = $candidate;

                    break;
                }
            }

            if ($child === null) {
                break;
            }

            $inner = $child;
        }

        return $inner->firstChild() ?? $inner;
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
        if (! $argument instanceof Part) {
            return false;
        }

        foreach ([$argument, ...$argument->children()] as $candidate) {
            if ($candidate->kind === NodeKind::NamedArgument) {
                return true;
            }
        }

        return false;
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
        if (! $part instanceof Part) {
            return false;
        }

        if ($part->kind === NodeKind::ConstantAccess) {
            return true;
        }

        return $part->kind === NodeKind::Literal && $part->firstChild()?->kind === NodeKind::Keyword;
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
        return self::isConstantName($part) ? trim((string) $part?->text) : null;
    }

    /** `->toLowerString()` on a name, which rules use so a comparison ignores how the name was written. */
    public static function lowerBytes(?string $text): ?string
    {
        return $text === null ? null : strtolower($text);
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
        return $argument instanceof Part && str_starts_with(ltrim($argument->text), '...');
    }

    /** `$this->foo(..)`, the receiver being `$this` rather than any expression. */
    public static function isThisMethodCall(NodeAnalysisContext $context, Part|Node|null $subject, string $method): bool
    {
        if (! self::selectorIs(self::selector($context, $subject), $method)) {
            return false;
        }

        $receiver = self::nthExpression($context, $subject, 0);

        return $receiver instanceof Part && $receiver->text === '$this';
    }

    /**
     * `array_any()` is PHP 8.4, and the generated rules should run on 8.1.
     *
     * @param list<string> $items
     * @param callable(string): bool $predicate
     */
    public static function anyOf(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $items
     * @param callable(string): bool $predicate
     */
    public static function allOf(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if (! $predicate($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Narrows to a method call, the counterpart of Rust's `as_method_call`.
     *
     * On this target the node kind already answers it, so this only unwraps and checks; it exists so a
     * generated binding reads the same as the Rust one.
     */
    public static function asMethodCall(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $part = $subject instanceof Part ? $subject : ($subject instanceof Node ? self::part($context, $subject) : null);
        $call = self::concreteCall($part);

        return $call?->kind === NodeKind::MethodCall ? $call : null;
    }

    /** Whether the enclosing class-like declaration has an extends clause at all. */
    public static function hasExtends(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return self::extendsNames($context, $subject) !== [];
    }

    public static function extendsIs(NodeAnalysisContext $context, Part|Node|null $subject, string $name): bool
    {
        foreach (self::extendsNames($context, $subject) as $extended) {
            if (strcasecmp($extended, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names in the enclosing declaration's extends clause.
     *
     * Written from the probe rather than from the shape one would expect: the clause is its own node
     * carrying the names, so this looks for it among the declaration's children instead of walking
     * every descendant, which would also pick up names from the body.
     *
     * @return list<string>
     */
    private static function extendsNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        // The same ancestor problem as `enclosingClassName()`, and the same fix: an `extends` question
        // asked from an expression target read an empty ancestor list and answered "no parent".
        [$file, $located] = self::locate($context, $node);

        $declaration = null;
        foreach ([$located, ...$file->getAncestors($located)] as $candidate) {
            if (in_array($candidate->kind->value, self::CLASS_LIKE_KINDS, true)) {
                $declaration = $candidate;
                break;
            }
        }

        if ($declaration === null) {
            return [];
        }

        $names = [];
        foreach ($file->getChildren($declaration) as $child) {
            if ($child->kind !== NodeKind::Extends) {
                continue;
            }

            foreach ($file->getChildren($child) as $part) {
                $resolved = $file->getResolvedName($part);
                $text = $resolved instanceof ResolvedName ? $resolved->name : trim($file->getText($part));
                if ($text !== '' && $text !== 'extends') {
                    $names[] = $text;
                }
            }
        }

        return $names;
    }

    public static function bytesContain(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_contains($haystack, $needle);
    }

    public static function bytesEndWith(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_ends_with($haystack, $needle);
    }

    public static function bytesStartWith(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_starts_with($haystack, $needle);
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
        return $subject !== null && in_array($subject, $values, true);
    }

    /** @param list<string> $names */
    public static function selectorIsOneOf(?Part $part, array $names): bool
    {
        return $part instanceof Part && in_array($part->text, $names, true);
    }

    /** Whether the inferred type is a single named object rather than a union, scalar or mixed. */
    public static function typeIsNamedObject(?Type $type): bool
    {
        return self::namedObjectName($type, false) !== null;
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
        $className = self::namedObjectName($type, false);
        if ($className === null) {
            return false;
        }

        if (strcasecmp($className, $name) === 0) {
            return true;
        }

        foreach ($context->codebase->getClassAncestors($className) as $ancestor) {
            if (strcasecmp($ancestor, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function typeHasMethod(NodeAnalysisContext $context, ?Type $type, string $method): bool
    {
        $className = self::namedObjectName($type, false);

        return $className !== null && $context->codebase->getMethod($className, $method) instanceof FunctionLikeMetadata;
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
        return self::namedObjectName($type, false);
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
        return self::namedObjectName($type, true);
    }

    private static function namedObjectName(?Type $type, bool $droppingNull): ?string
    {
        if (! $type instanceof Type) {
            return null;
        }

        $names = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($droppingNull && $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            if (! $atomic instanceof NamedObjectType) {
                return null;
            }

            $names[strtolower($atomic->name)] = $atomic->name;
        }

        return count($names) === 1 ? reset($names) : null;
    }

    /**
     * The items of a constant declaration: `const A = 1, B = 2;` has two.
     *
     * @return list<Part>
     */
    public static function constantItems(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ClassLikeConstantItem || $child->kind === NodeKind::ConstantItem) {
                $out[] = self::part($context, $child);
            }
        }

        return $out;
    }

    /** A constant item's name, without its value. */
    public static function constantItemName(?Part $item): ?string
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ($item->children() as $child) {
            if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                return $child->text;
            }
        }

        return null;
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
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ([$node, ...$context->source->getChildren($node)] as $candidate) {
            foreach ($context->source->getChildren($candidate) as $child) {
                if ($child->kind === NodeKind::Hint) {
                    return self::part($context, $child);
                }
            }
        }

        return null;
    }

    public static function hintIsUnion(?Part $hint): bool
    {
        return self::hintShape($hint) === NodeKind::UnionHint;
    }

    public static function hintIsIntersection(?Part $hint): bool
    {
        return self::hintShape($hint) === NodeKind::IntersectionHint;
    }

    private static function hintShape(?Part $hint): ?NodeKind
    {
        return $hint?->firstChild()?->kind;
    }

    /**
     * The members of a union or intersection hint, or the hint itself when it is neither.
     *
     * @return list<Part>
     */
    public static function hintParts(?Part $hint): array
    {
        if (! $hint instanceof Part) {
            return [];
        }

        $shape = $hint->firstChild();
        if (! $shape instanceof Part || ($shape->kind !== NodeKind::UnionHint && $shape->kind !== NodeKind::IntersectionHint)) {
            return [$hint];
        }

        $out = [];
        foreach ($shape->children() as $child) {
            if ($child->kind === NodeKind::Hint) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** A hint that is a plain name: neither a union nor an intersection, and present. */
    public static function hintIsName(?Part $hint): bool
    {
        return $hint instanceof Part && ! self::hintIsUnion($hint) && ! self::hintIsIntersection($hint);
    }

    /** Whether a name is written entirely in upper case, as a constant convention check. */
    public static function isUppercase(?string $value): bool
    {
        return $value !== null && $value === strtoupper($value);
    }

    /** A hint's written name, resolved through the file's imports where possible. */
    public static function hintName(NodeAnalysisContext $context, ?Part $hint): ?string
    {
        if (! $hint instanceof Part) {
            return null;
        }

        $resolved = $context->source->getResolvedName($hint->node);

        return $resolved instanceof ResolvedName ? $resolved->name : $hint->text;
    }

    public static function hintNameIs(NodeAnalysisContext $context, ?Part $hint, string $name): bool
    {
        $written = self::hintName($context, $hint);

        return $written !== null && strcasecmp($written, $name) === 0;
    }

    /**
     * The property declarations of a class-like body.
     *
     * @return list<Part>
     */
    public static function classProperties(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $member) {
            if ($member->kind !== NodeKind::ClassLikeMember) {
                continue;
            }

            foreach ($context->source->getChildren($member) as $child) {
                if (in_array($child->kind->value, ['Property', 'PlainProperty', 'HookedProperty'], true)) {
                    $out[] = self::part($context, $child);
                }
            }
        }

        return $out;
    }

    public static function fileEndsWith(NodeAnalysisContext $context, string $suffix): bool
    {
        return str_ends_with($context->source->path, $suffix);
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
     * The full tree of the file being analysed, and its nodes indexed by kind and span.
     *
     * One file, not a map of them: `getSourceFile()` is a host round-trip on first call and `getNodes()`
     * walks the whole tree, and a node hook asks per node, so calling them per question cost 6.4s wall and
     * 12.8s CPU on a 676-file corpus against 0.89s / 0.77s without. Memoising the current file brings that
     * back to 0.99s / 1.05s. A single slot keeps a long-lived worker bounded; hooks arrive grouped per
     * file, so a second file simply replaces the first.
     *
     * @var array{string, SourceFile, array<string, Node>}|null
     */
    private static ?array $tree = null;

    /**
     * The whole file, and this node's counterpart inside it.
     *
     * A node hook is handed `TargetSubtree`, which embeds "each targeted node's concrete-syntax subtree".
     * So the target's `parentId` is null and `$context->source->getAncestors()` is empty — every question
     * about an *enclosing* declaration silently answered "none", and five emitted rules reported nothing
     * for it while parsing, loading and running.
     *
     * `$context->analysis->getSourceFile()` returns the complete analysed syntax. The target cannot simply
     * be handed to it: the same node is a different object there, with a real parent chain, so it is
     * relocated by kind and span first.
     *
     * @return array{SourceFile, Node}
     */
    private static function locate(NodeAnalysisContext $context, Node $node): array
    {
        $path = $context->source->path;
        if (self::$tree === null || self::$tree[0] !== $path) {
            $file = $context->analysis->getSourceFile();
            $index = [];
            foreach ($file->getNodes() as $candidate) {
                $key = $candidate->kind->value . ':' . $candidate->span->start . ':' . $candidate->span->end;
                // Two nodes of one kind at one span would make the index lose an entry, and relocation
                // would then answer with the wrong node instead of failing. Detected here, where it costs
                // one lookup per node, rather than trusted: a span identifying a node is an assumption.
                if (isset($index[$key])) {
                    throw new LogicException(sprintf('Two %s nodes share offsets %d-%d in %s, so a span does not identify one.', $candidate->kind->value, $candidate->span->start, $candidate->span->end, $file->path));
                }

                $index[$key] = $candidate;
            }

            self::$tree = [$path, $file, $index];
        }

        [, $file, $index] = self::$tree;
        $key = $node->kind->value . ':' . $node->span->start . ':' . $node->span->end;
        $matches = isset($index[$key]) ? [$index[$key]] : [];

        // Neither branch below has been seen across the corpus. They throw rather than picking a candidate
        // because guessing here is how the original bug behaved: an unanswerable question that answers
        // anyway is invisible, and this method exists to stop exactly that.
        if ($matches === []) {
            throw new LogicException(sprintf(
                'No %s node at offsets %d-%d in the full tree of %s.',
                $node->kind->value,
                $node->span->start,
                $node->span->end,
                $file->path,
            ));
        }

        return [$file, $matches[0]];
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
        if (preg_match('/^\\s*namespace\\s+([^;{\\s]+)\\s*[;{]/m', $context->source->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], '\\');
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
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $items = [];
        foreach ($context->source->getChildren($node) as $child) {
            // A property declaration may be plain or hooked; both hold the items.
            if (! in_array($child->kind->value, ['PlainProperty', 'HookedProperty'], true)) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $item) {
                if ($item->kind === NodeKind::PropertyItem) {
                    $items[] = self::part($context, $item);
                }
            }
        }

        return $items;
    }

    /**
     * A property item's name, without the `$`.
     *
     * `$with = ['author']` gives `with`. The name is a `DirectVariable` under a `PropertyConcreteItem` for an
     * initialised property, or directly under the item for an uninitialised one, so both are searched.
     */
    public static function propertyItemName(?Part $item): ?string
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ([$item, ...$item->children()] as $candidate) {
            foreach ($candidate->children() as $child) {
                if ($child->kind === NodeKind::DirectVariable) {
                    return ltrim($child->text, '$');
                }
            }
        }

        return null;
    }

    /** The enclosing class-like declaration's name, or null at top level. */
    public static function enclosingClassName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        [$file, $located] = self::locate($context, $node);

        // The node itself counts. A rule hooked on the class declaration is handed that declaration, so
        // walking only ancestors finds the *enclosing* class and returns null at top level, which made
        // every class-level name test silently fail.
        foreach ([$located, ...$file->getAncestors($located)] as $ancestor) {
            // Compared by backed value, not by case or by name. `NodeKind::Class` does not reference
            // the case at all, because PHP special-cases `::class` and silently yields the class-name
            // string, so every comparison against it is true and this method always returned null. The
            // case is spelled `Class_` while its value stays `Class`, so the value is the stable thing.
            if (! in_array($ancestor->kind->value, self::CLASS_LIKE_KINDS, true)) {
                continue;
            }

            foreach ($file->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                    $resolved = $file->getResolvedName($child);

                    return $resolved instanceof ResolvedName ? $resolved->name : trim($file->getText($child));
                }
            }
        }

        return null;
    }

    public static function isInClass(NodeAnalysisContext $context, Part|Node|null $node): bool
    {
        return self::enclosingClassName($context, $node) !== null;
    }

    /**
     * Whether the enclosing class is, or descends from, `$name`.
     *
     * `getClassAncestors()` answers in lowercase, which silently disabled every parent exclusion in an
     * earlier port until a probe printed the values, so the comparison is case insensitive here.
     */
    public static function enclosingClassIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        $className = self::enclosingClassName($context, $node);
        if ($className === null) {
            return false;
        }

        if (strcasecmp($className, $name) === 0) {
            return true;
        }

        foreach ($context->codebase->getClassAncestors($className) as $ancestor) {
            if (strcasecmp($ancestor, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** The class-declaration hook's `metadata_is`, which asks the same question. */
    public static function metadataIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        return self::enclosingClassIs($context, $node, $name);
    }
}
