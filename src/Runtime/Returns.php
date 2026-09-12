<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;

/**
 * Whether a function-like body hands a value back, which `NoReturnSetterMethodRule` asks two ways at once.
 *
 * The rule's own `hasReturnReturnFunctionLike()` runs a php-parser traverser and then a node finder, and the
 * two disagree about closures on purpose. That asymmetry is the original's behaviour rather than an oversight,
 * so both halves are ported as written:
 *
 * - **The return half is scoped.** `HasScopedReturnNodeVisitor::enterNode()` answers
 *   `DONT_TRAVERSE_CURRENT_AND_CHILDREN` for a `Closure`, so a `return` written inside a closure is not the
 *   setter's return. It stops at `Closure` and at nothing else — not `ArrowFunction`, not a nested named
 *   `Function` — so a `return` inside either of those *does* count. A named function declared in a method
 *   body is legal PHP and php-parser descends into it, which is why {@see self::hasScopedReturnValue()}
 *   descends too. Stopping at every function-like would read better and report differently.
 * - **The yield half is blind.** `TypeAwareNodeFinder::findFirstInstanceOf()` wraps php-parser's `NodeFinder`,
 *   which recurses into everything, so a `yield` inside a closure inside a setter makes the rule fire.
 *
 * Three shapes were measured before either walk was written, in `internal/probe-scoped-return-and-yield.php`,
 * and two of them do not translate the way the kind names suggest:
 *
 * - **`Yield` is a wrapper, not php-parser's `Yield_`.** Mago hangs one of three kinds under it —
 *   `YieldValue` for `yield` and `yield $v`, `YieldPair` for `yield $k => $v`, and `YieldFrom` for
 *   `yield from $xs`. php-parser's `Yield_` is the first two and `YieldFrom` is a separate class the finder
 *   is not looking for, so matching `Yield` would fire on `yield from` where the original is silent. The
 *   kinds named below are the two, and the probe carries `fromYield()` beside `valueYield()` as the row that
 *   tells them apart.
 * - **A valueless `return` is a `Return` with no `Expression` child.** php-parser gives `Return_` a nullable
 *   `expr` and the visitor counts only a non-null one; mago says the same thing by omitting the child, so
 *   that is the discriminator rather than an emptiness test on the text.
 * - **An arrow function synthesises no `Return`.** `fn () => 1` is an `ArrowFunction` holding a bare
 *   `Expression`, so the scoped walk cannot mistake it for a returned value. That is the control: had mago
 *   desugared it, the walk would report on a setter PHPStan leaves alone.
 *
 * @internal to the runtime. An emitted plugin calls {@see Support}, never this.
 */
final class Returns
{
    /** The two kinds php-parser's `Yield_` covers. `YieldFrom` is a class of its own and is not one of them. */
    private const array YIELD_KINDS = ['YieldValue', 'YieldPair'];

    /**
     * Whether this function-like returns a value of its own, or yields anywhere inside it.
     *
     * The whole of `NoReturnSetterMethodRule::hasReturnReturnFunctionLike()`: the scoped return first,
     * because it is the cheaper walk and the common answer, then the blind search for a yield.
     */
    public static function hasReturnValueOrYield(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return false;
        }

        return self::hasScopedReturnValue($context, $node) || self::containsYield($context, $node);
    }

    /**
     * A `return <expr>;` below this node, not counting one written inside a closure.
     *
     * The closure stop is the whole difference between this and {@see Tree::findKind()}, which recurses
     * blindly by design. A setter assigning `function () { return 1; }` to a local returns nothing itself.
     */
    private static function hasScopedReturnValue(NodeAnalysisContext $context, Node $node): bool
    {
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind->value === 'Closure') {
                continue;
            }

            if ($child->kind->value === 'Return' && self::returnsAValue($context, $child)) {
                return true;
            }

            if (self::hasScopedReturnValue($context, $child)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a `Return` was written with something to return, which mago says by giving it an `Expression`. */
    private static function returnsAValue(NodeAnalysisContext $context, Node $return): bool
    {
        foreach ($context->source->getChildren($return) as $child) {
            if ($child->kind->value === 'Expression') {
                return true;
            }
        }

        return false;
    }

    /** A `yield` or `yield $k => $v` anywhere below this node, closures included, which is what `NodeFinder` does. */
    private static function containsYield(NodeAnalysisContext $context, Node $node): bool
    {
        foreach ($context->source->getChildren($node) as $child) {
            if (in_array($child->kind->value, self::YIELD_KINDS, true)) {
                return true;
            }

            if (self::containsYield($context, $child)) {
                return true;
            }
        }

        return false;
    }
}
