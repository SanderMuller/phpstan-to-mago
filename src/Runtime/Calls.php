<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Calls, arguments and the expressions around them.
 *
 * Mago wraps a call: `Call` is a category node whose child is the concrete kind, and `Access` splits into
 * property and class-constant access the same way. Almost everything here exists because of that wrapping —
 * a predicate written against the outer kind matches nothing, and one written against the inner kind misses
 * the wrapper.
 */
final class Calls
{
    /**
     * The node kinds a *written* member name is spelled with, as opposed to a computed one.
     *
     * Probed across the six accesses php-parser puts a `->name` on. `DirectVariable` belongs here because a
     * static property's written name is `$prop`, which is a variable in the tree and an identifier to the rule.
     *
     * @var list<NodeKind>
     */
    private const array WRITTEN_NAME_KINDS = [
        NodeKind::Identifier,
        NodeKind::LocalIdentifier,
        // A name written with a leading `\` or a namespace prefix is as *written* as a bare one, and both were
        // missing. `\is_string(..)` arrives as an `Identifier` whose child is a `FullyQualifiedIdentifier`, and
        // this method descends into that child — so a written qualified name answered "not written" and every
        // rule asking `! $node->name instanceof Expr` inverted on it. `NoDynamicNameRule` reported 169 sites on
        // `nikic/php-parser`, which writes `\`-prefixed globals throughout, and three of them are the three
        // `\is_string(..)` calls in `BuilderFactory.php`.
        NodeKind::FullyQualifiedIdentifier,
        NodeKind::QualifiedIdentifier,
        NodeKind::DirectVariable,
    ];

    /**
     * The nth `Expression` child, unwrapped.
     *
     * A method call's receiver, a function call's name and both sides of an assignment are all the
     * inner node of an `Expression` child, distinguished only by position.
     */
    public static function nthExpression(NodeAnalysisContext $context, Part|Node|null $subject, int $index): ?Part
    {
        $node = self::throughTheCallWrapper($context, $subject);
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

            return Tree::part($context, $inner ?? $child);
        }

        return null;
    }

    /** The class side of a class-constant access or static call. */
    public static function classPart(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        return self::nthExpression($context, $subject, 0);
    }

    /**
     * The middle arm of a ternary, or null for an elvis that has none.
     *
     * php-parser spells the absent arm `$node->if === null`, and mago spells it `then: Option<&Expression>` on
     * its `Conditional` — the same distinction, so a rule asking which one this is gets an exact answer rather
     * than an approximation.
     *
     * Counted rather than read by position, because position cannot tell them apart: index 1 is the middle arm
     * of a full ternary and the *else* arm of an elvis. Probed on both spellings in one file — `$a > 1 ? 'big'
     * : 'small'` gives three `Expression` children and `$a ?: 'fallback'` gives two, with no child for the `?`
     * or the `:` to shift the count.
     */
    public static function conditionalThen(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $arms = 0;
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::Expression) {
                ++$arms;
            }
        }

        return $arms === 3 ? self::nthExpression($context, $subject, 1) : null;
    }

    /** The member selector of a method call: `->expects(..)` gives `expects`. */
    public static function selector(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = self::throughTheCallWrapper($context, $subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ClassLikeMemberSelector) {
                $inner = $context->source->getChildren($child)[0] ?? null;

                return Tree::part($context, $inner ?? $child);
            }
        }

        return null;
    }

    public static function argumentList(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = self::throughTheCallWrapper($context, $subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            // An attribute spells its arguments `PartialArgumentList`, a different kind from a call's, and
            // reading only `ArgumentList` found nothing there -- so a rule looping over an attribute's
            // arguments looped over an empty list, ran, and reported nothing.
            if ($child->kind === NodeKind::ArgumentList || $child->kind === NodeKind::PartialArgumentList) {
                return Tree::part($context, $child);
            }
        }

        return null;
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
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $wanted = [NodeKind::ClassLikeConstantSelector, NodeKind::ClassLikeMemberSelector, NodeKind::Variable];
        foreach ($context->source->getChildren($node) as $child) {
            if (in_array($child->kind, $wanted, true)) {
                return Tree::part($context, $child);
            }
        }

        // A function call names its target with an expression rather than a selector.
        return $node->kind === NodeKind::FunctionCall ? self::nthExpression($context, $node, 0) : null;
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
        return ! self::isWrittenName(self::namePart($context, $subject));
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
        if (! $part instanceof Part) {
            return false;
        }

        // A `Variable` name part means opposite things in two positions, and the part alone cannot tell them
        // apart: `Holder::$prop` and `$next(1)` both spell it `Variable > DirectVariable`. php-parser splits
        // them — a static property's written name is a `VarLikeIdentifier`, and a function call's written name
        // is a `Name`, so a variable there *is* an `Expr` — and the parent node is what says which position
        // this is. Without it every dynamic call written as a plain variable answered "written", and
        // `NoDynamicNameRule` was silent on the three `$next($request)` calls of a real consumer's middleware
        // that PHPStan reports.
        // Only in the static-property position, and `$$n` is still computed there: `Holder::$$n` spells the
        // part `Variable > NestedVariable`, which the list below already rejects, so the position test gates
        // the descent rather than replacing it.
        if ($part->kind === NodeKind::Variable
            && $part->source->getParent($part->node)?->kind !== NodeKind::StaticPropertyAccess
        ) {
            return false;
        }

        $inner = $part->children()[0] ?? null;

        return in_array(($inner instanceof Part ? $inner : $part)->kind, self::WRITTEN_NAME_KINDS, true);
    }

    /** The selector's own name, which is case sensitive in PHP as method names are compared. */
    public static function selectorIs(?Part $part, string $literal): bool
    {
        return $part instanceof Part && $part->text === $literal;
    }

    public static function isMethodCall(?Part $part): bool
    {
        return self::concreteCall($part)?->kind === NodeKind::MethodCall;
    }

    public static function isStaticCall(?Part $part): bool
    {
        return self::concreteCall($part)?->kind === NodeKind::StaticMethodCall;
    }

    /**
     * Whether the part is a plain function call, which is php-parser's `Expr\FuncCall`.
     *
     * Through the same `Call` unwrapping as {@see isMethodCall} and {@see isStaticCall}: Mago wraps every call
     * in a `Call` node whose first child carries the concrete kind, and asking the wrapper answers no for all
     * three.
     */
    public static function isFunctionCall(?Part $part): bool
    {
        return self::concreteCall($part)?->kind === NodeKind::FunctionCall;
    }

    /** Whether the part is a `Foo::BAR` access, which is php-parser's `Expr\ClassConstFetch`. */
    public static function isClassConstantAccess(?Part $part): bool
    {
        return self::concreteMemberAccess($part)?->kind === NodeKind::ClassConstantAccess;
    }

    /**
     * The concrete access under an `Access` wrapper, or the part itself.
     *
     * Named apart from `concreteAccess()` below, which answers a different question — that one takes a context
     * and searches for any of several kinds. This one has a `Part` already and asks what the wrapper holds.
     *
     * Mago wraps a member access the way it wraps a call: `Foo::BAR` arrives as an `Access` whose child is a
     * `ClassConstantAccess`, and `$a->b` as an `Access` whose child is a `PropertyAccess`. Measured — a probe
     * over `$this->configure(ref(), Marker::NAME)` reported `kind=Access text=Marker::NAME`, and the predicate
     * written against the concrete kind answered no.
     */
    private static function concreteMemberAccess(?Part $part): ?Part
    {
        if (! $part instanceof Part) {
            return null;
        }

        return $part->kind === NodeKind::Access ? $part->firstChild() : $part;
    }

    /**
     * The call node itself, through the `Call` category node mago wraps a *nested* call in.
     *
     * The hook's own node is the concrete `MethodCall`, so navigating from it needs no unwrap and none of
     * these helpers had one. A call the rule reached through a field is not: measured on
     * `$routes->import('..')->prefix('/x')`, where the receiver of `prefix` arrives as `Call` with a single
     * `MethodCall` child. `isMethodCall()` already went through the wrapper — that is what
     * {@see concreteCall()} does — so the kind test said yes and every navigation off the same part then
     * searched the wrapper's children, found none, and answered null. The guard chain read as a rule that
     * simply never matched.
     */
    private static function throughTheCallWrapper(NodeAnalysisContext $context, Part|Node|null $subject): ?Node
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node || $node->kind !== NodeKind::Call) {
            return $node;
        }

        return $context->source->getChildren($node)[0] ?? $node;
    }

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
        // Through the wrapper, and against the *concrete* kind. Comparing against `Access` itself answered yes
        // for every member access there is — `Foo::BAR` included — so a rule asking `instanceof PropertyFetch`
        // was answered yes about a class constant. Found while adding the class-constant predicate beside it,
        // where a probe reported `kind=Access text=Marker::NAME`.
        //
        // No test covers this one either way: nothing in the corpus or the fixtures reaches
        // `is_property_fetch` yet, so reverting the narrowing breaks nothing. Corrected anyway rather than left
        // for the first rule that does reach it to widen silently, and recorded as untested rather than gated.
        return self::concreteMemberAccess($part)?->kind === NodeKind::PropertyAccess;
    }

    public static function isArrayDimFetch(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::ArrayAccess;
    }

    /** @return list<Part> the positional arguments, in source order */
    public static function arguments(?Part $list): array
    {
        if (! $list instanceof Part) {
            return [];
        }

        $out = [];
        foreach ($list->children() as $child) {
            // `PartialArgument` for the same reason as `PartialArgumentList` above: an attribute spells both
            // halves differently from a call. Filtering on `Argument` alone dropped every attribute argument
            // and left the loop body unreachable.
            if ($child->kind === NodeKind::Argument || $child->kind === NodeKind::PartialArgument) {
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
     * Narrows to a method call, the counterpart of Rust's `as_method_call`.
     *
     * On this target the node kind already answers it, so this only unwraps and checks; it exists so a
     * generated binding reads the same as the Rust one.
     */
    public static function asMethodCall(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $part = $subject instanceof Part ? $subject : ($subject instanceof Node ? Tree::part($context, $subject) : null);
        $call = self::concreteCall($part);

        return $call?->kind === NodeKind::MethodCall ? $call : null;
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
        $access = self::concreteAccess($context, $subject, [NodeKind::PropertyAccess, NodeKind::NullSafePropertyAccess]);

        return $access instanceof Part ? self::nthExpression($context, $access, 0) : null;
    }

    /**
     * The array an index read reads from — php-parser's `ArrayDimFetch->var`.
     *
     * `$arr['k']` is `ArrayAccess → [Expression($arr), Expression('k')]`, with no `Access` category node above
     * it, which is why this is not the same walk as a property access.
     */
    public static function dimFetchTarget(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $access = self::concreteAccess($context, $subject, [NodeKind::ArrayAccess]);

        return $access instanceof Part ? self::nthExpression($context, $access, 0) : null;
    }

    /**
     * A part narrowed to one of the given access kinds, looking through the `Access` category node.
     *
     * @param list<NodeKind> $kinds
     */
    private static function concreteAccess(NodeAnalysisContext $context, Part|Node|null $subject, array $kinds): ?Part
    {
        $part = self::asPart($context, $subject);
        if (! $part instanceof Part) {
            return null;
        }

        if (in_array($part->kind, $kinds, true)) {
            return $part;
        }

        foreach ($part->children() as $child) {
            if (in_array($child->kind, $kinds, true)) {
                return $child;
            }
        }

        return null;
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
        if ($subject instanceof Part) {
            return $subject;
        }

        return $subject instanceof Node ? Tree::part($context, $subject) : null;
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
        $node = Tree::node($array);
        if (! $node instanceof Node) {
            return [];
        }

        $elements = [];
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ArrayElement) {
                $elements[] = Tree::part($context, $child);
            }
        }

        return $elements;
    }
}
