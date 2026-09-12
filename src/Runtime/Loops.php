<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * The children of a loop, which mago wraps and php-parser does not.
 *
 * Split out of {@see Calls} when that class reached 87 against a limit of 80, on the call graph rather than
 * on the subject: these three reach `Calls::nthExpression()` and nothing else, and nothing in `Calls` reaches
 * back. {@see Support} keeps the shipped names, so no emitted byte moved with them.
 *
 * @internal to the runtime. An emitted plugin calls {@see Support}, never this.
 */
final class Loops
{
    /**
     * A foreach's key variable, or null when it is written without one.
     *
     * The same split {@see Calls::arrayElementKey()} reads, one level down. php-parser gives a `Foreach_` a nullable
     * `keyVar`; mago hangs a `ForeachTarget` off the loop and puts one of two kinds under it —
     * `ForeachValueTarget` with a single `Expression`, or `ForeachKeyValueTarget` with two, key first. So
     * "does this loop bind a key" is the target's own kind, which is an exact answer rather than an
     * approximation.
     *
     * Probed rather than assumed, over the three shapes a foreach is written in: `as $v`, `as $k => $v`, and
     * the destructuring `as [$a, $b]`. The last is a `ForeachValueTarget` whose expression is the array,
     * which is what php-parser answers for it too.
     */
    public static function foreachKey(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $target = self::target($context, $subject, NodeKind::ForeachKeyValueTarget);

        return $target instanceof Part ? Calls::nthExpression($context, $target, 0) : null;
    }

    /**
     * A foreach's value variable, which every loop has.
     *
     * {@see foreachKey} says how the two targets differ. The value is the only expression under a
     * `ForeachValueTarget` and the second under a `ForeachKeyValueTarget`, so the index follows the kind.
     */
    public static function foreachValue(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $keyed = self::target($context, $subject, NodeKind::ForeachKeyValueTarget);
        if ($keyed instanceof Part) {
            return Calls::nthExpression($context, $keyed, 1);
        }

        $plain = self::target($context, $subject, NodeKind::ForeachValueTarget);

        return $plain instanceof Part ? Calls::nthExpression($context, $plain, 0) : null;
    }

    /**
     * The loop's target of the given kind, reached through the `ForeachTarget` wrapper that always holds it.
     *
     * Searched two levels rather than one because the wrapper is unconditional: a `Foreach` node's children
     * are the `foreach` keyword, the iterable `Expression`, the `as` keyword, a `ForeachTarget` and a
     * `ForeachBody`, and the kind that says whether a key is bound sits inside the fourth.
     */
    private static function target(NodeAnalysisContext $context, Part|Node|null $subject, NodeKind $kind): ?Part
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::ForeachTarget) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $target) {
                if ($target->kind === $kind) {
                    return Tree::part($context, $target);
                }
            }
        }

        return null;
    }
}
