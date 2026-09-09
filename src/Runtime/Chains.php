<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;

/**
 * Walks a chain of calls, which is what a fluent configuration API gives a rule.
 *
 * Its own class rather than a method on {@see Calls}: that one is at the class-complexity limit, and this
 * repository's split pattern is a new class beside the others rather than a baseline entry. `Calls` holds the
 * primitives this builds on, so the dependency runs one way.
 */
final class Chains
{
    /**
     * The first call in a receiver chain whose method is the one named, or null when none is.
     *
     * `$services->set(X)->arg('%p%')` is nested method calls, and a rule handed the innermost one walks
     * outwards through the receiver to find the `set()` that named the service.
     * `PreferAutowireAttributeOverConfigParamRule` writes that as a `while` over `->var`, and this stands in
     * for the loop.
     *
     * The receiver is child 0, which is how the emitted plugins already read it -- see
     * `NoServiceSameNameSetClassRule`'s `writtenName(nthExpression($call, 0))`. Bounded by the chain: each
     * step moves strictly inwards, so a malformed tree stops rather than looping.
     */
    public static function chainedCallNamed(
        NodeAnalysisContext $context,
        Part|Node|null $subject,
        string $method,
    ): ?Part {
        $node = Tree::node($subject);
        $current = $node instanceof Node ? Tree::part($context, $node) : null;

        while ($current instanceof Part && Calls::isMethodCall($current)) {
            if (Calls::selectorIs(Calls::selector($context, $current), $method)) {
                return $current;
            }

            $current = Calls::nthExpression($context, $current, 0);
        }

        return null;
    }
}
