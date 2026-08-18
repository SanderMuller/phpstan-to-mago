<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use LogicException;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

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
        $argument = self::arguments($list)[$index] ?? null;
        if ($argument === null) {
            return null;
        }

        $inner = $argument;
        foreach ([[NodeKind::PositionalArgument, NodeKind::NamedArgument], [NodeKind::Expression]] as $layer) {
            $child = $inner->firstChild();
            if ($child === null || ! in_array($child->kind, $layer, true)) {
                break;
            }

            $inner = $child;
        }

        return $inner->firstChild() ?? $inner;
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

        $declaration = null;
        foreach ([$node, ...$context->source->getAncestors($node)] as $candidate) {
            if (in_array($candidate->kind->value, self::CLASS_LIKE_KINDS, true)) {
                $declaration = $candidate;
                break;
            }
        }

        if ($declaration === null) {
            return [];
        }

        $names = [];
        foreach ($context->source->getChildren($declaration) as $child) {
            if ($child->kind !== NodeKind::Extends) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $part) {
                $resolved = $context->source->getResolvedName($part);
                $text = $resolved instanceof ResolvedName ? $resolved->name : trim($context->source->getText($part));
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

    /** @param list<string> $names */
    public static function selectorIsOneOf(?Part $part, array $names): bool
    {
        return $part instanceof Part && in_array($part->text, $names, true);
    }

    /** Whether the inferred type is a single named object rather than a union, scalar or mixed. */
    public static function typeIsNamedObject(?Type $type): bool
    {
        return self::namedObjectName($type) !== null;
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
        $className = self::namedObjectName($type);
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
        $className = self::namedObjectName($type);

        return $className !== null && $context->codebase->getMethod($className, $method) instanceof FunctionLikeMetadata;
    }

    private static function namedObjectName(?Type $type): ?string
    {
        if (! $type instanceof Type) {
            return null;
        }

        $names = [];
        foreach ($type->atomicTypes as $atomic) {
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

    /** The enclosing class-like declaration's name, or null at top level. */
    public static function enclosingClassName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = self::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        // The node itself counts. A rule hooked on the class declaration is handed that declaration, so
        // walking only ancestors finds the *enclosing* class and returns null at top level, which made
        // every class-level name test silently fail.
        foreach ([$node, ...$context->source->getAncestors($node)] as $ancestor) {
            // Compared by backed value, not by case or by name. `NodeKind::Class` does not reference
            // the case at all, because PHP special-cases `::class` and silently yields the class-name
            // string, so every comparison against it is true and this method always returned null. The
            // case is spelled `Class_` while its value stays `Class`, so the value is the stable thing.
            if (! in_array($ancestor->kind->value, self::CLASS_LIKE_KINDS, true)) {
                continue;
            }

            foreach ($context->source->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                    $resolved = $context->source->getResolvedName($child);

                    return $resolved instanceof ResolvedName ? $resolved->name : trim($context->source->getText($child));
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
