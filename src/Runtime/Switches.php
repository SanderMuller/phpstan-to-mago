<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Navigating a `switch`, which is three levels deeper than php-parser spells it.
 *
 * Its own class rather than more of {@see Members}, which reached the cognitive-complexity limit with these
 * in it. The runtime is out of the baseline and stays there by splitting, which is the move recorded for
 * `Support` itself -- and the split is by call graph: both methods here are reached only from a rule walking
 * a switch, and neither is called from anywhere else in the runtime.
 */
final class Switches
{
    /**
     * The cases a `switch` writes, one `SwitchCase` each, which is php-parser's `$node->cases`.
     *
     * Measured with `run-syntax-probe.php` rather than read off the `NodeKind` list, because the nesting is
     * three deep and not guessable: `Switch` holds a `SwitchBody`, which holds a
     * `SwitchBraceDelimitedBody` or a `SwitchColonDelimitedBody`, and only that holds the cases. Descending
     * one level short returns nothing, which is the silent shape {@see statementsOf()} records for a method
     * body -- a rule walking the list finds no case and reports nothing.
     *
     * @return list<Part>
     */
    public static function switchCasesOf(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        foreach ([NodeKind::SwitchBody, NodeKind::SwitchBraceDelimitedBody] as $wrapper) {
            foreach ($context->source->getChildren($node) as $child) {
                if ($child->kind === $wrapper) {
                    $node = $child;

                    break;
                }
            }
        }

        $cases = [];
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::SwitchCase) {
                $cases[] = Tree::part($context, $child);
            }
        }

        return $cases;
    }

    /**
     * The expression a `switch` case matches on, or null for `default`.
     *
     * php-parser spells the same distinction as `$case->cond === null`, so a rule's null test translates to
     * this returning null rather than to a kind comparison at the call site. A `SwitchDefaultCase` has no
     * `Expression` child at all -- measured, not assumed -- so the absence is the answer rather than a
     * separate flag.
     */
    public static function switchCaseCondition(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::SwitchExpressionCase) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $inner) {
                if ($inner->kind === NodeKind::Expression) {
                    return Tree::part($context, $inner);
                }
            }
        }

        return null;
    }
}
