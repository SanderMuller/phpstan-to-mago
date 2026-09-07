<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;

/**
 * What a Symfony config closure declares about itself, which `FileNameMatchesExtensionRule` reads.
 *
 * `findExtensionName()` runs php-parser's `NodeFinder` with a callback that mutates a captured variable — a
 * walk whose answer is a side effect rather than a value, which the vocabulary has no statements for. So the
 * *question* is ported, the way {@see Returns} and {@see StaticReflectionTypes} are.
 *
 * **The callback's `STOP_TRAVERSAL` does nothing, and the port was written twice because of it.** The callback
 * ends with `return NodeVisitor::STOP_TRAVERSAL;`, which reads as "stop at the first `extension()` call". It
 * does not: `NodeFinder::find()` takes a *predicate*, not a visitor callback, and `FindingVisitor::enterNode()`
 * uses the return value only for its truthiness before returning `null` itself. The traversal is never
 * stopped. So the answer is **the last string argument of the last `extension()` call anywhere below the
 * closure**, and a first-call-wins port is silent where the original reports.
 *
 * That was not settled by reading the rule. The first port stopped at the first call, and PHPStan reported a
 * finding the port did not on a fixture written to pin the stop — `stopsOnTheFirstCall` in the example pair,
 * now named for what it actually measures. Reading `NodeFinder::find()` afterwards explained it. The rule's
 * own author appears to have believed the stop worked; the port has to match the behaviour, not the intent.
 *
 * Two further shapes, both ported as written:
 *
 * - **Within one call the last string argument wins.** The `foreach` assigns without breaking, so
 *   `extension('a', 'b')` contributes `b`. Confirmed by a row the original leaves silent.
 * - **The walk is blind.** It descends into nested closures, so an `extension()` call written inside one
 *   counts.
 *
 * Only `MethodCall` matches, because that is the class php-parser tests. Mago spells a nullsafe call as its own
 * `NullSafeMethodCall` kind, and matching it too would report where the original is silent — the same hazard
 * `internal/handoff-multi-kind-hook-is-not-a-redesign.md` records for the assert rules, from the other side.
 *
 * @internal to the runtime. An emitted plugin calls this directly, the way it calls {@see RectorAutoloadedTypes}.
 */
final class ConfigClosures
{
    /**
     * `findExtensionName()` — the extension a config closure names, or null.
     *
     * Null covers three situations the original also does not distinguish: no `extension()` call anywhere, one
     * carrying no string argument, and a subject that is not a node at all.
     */
    public static function extensionName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $name = null;
        self::readEveryExtensionCall($context, $node, $name);

        return $name;
    }

    /**
     * Reads every `extension()` call below this node into `$name`, so the last one written wins.
     *
     * No early exit, which is the whole correction above: the walk the original runs visits the complete
     * subtree whatever its callback returns.
     */
    private static function readEveryExtensionCall(
        NodeAnalysisContext $context,
        Node $node,
        ?string &$name,
    ): void {
        if ($node->kind->value === 'MethodCall') {
            $selector = Calls::selector($context, $node);

            if (Names::selectorIsIdentifier($selector) && Calls::selectorIs($selector, 'extension')) {
                foreach (Calls::arguments(Calls::argumentList($context, $node)) as $argument) {
                    $value = Calls::argumentValue($argument);
                    $literal = $value instanceof Part ? CstLiteral::plainString($value->text) : null;

                    if ($literal !== null) {
                        $name = $literal;
                    }
                }
            }
        }

        foreach ($context->source->getChildren($node) as $child) {
            self::readEveryExtensionCall($context, $child, $name);
        }
    }
}
