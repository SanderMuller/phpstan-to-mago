<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/**
 * Sets only attributes the rule allows: php-parser's own keys and the printer keys Rector reads back.
 *
 * The class below sets a custom one and is not a Rector rule at all, which is the guard the rule opens with —
 * a port that skips it reports here and PHPStan does not.
 */
final class AllowedAttributeRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        $node->setAttribute('kind', 1);
        $node->setAttribute('wrapped_in_parentheses', true);

        return $node;
    }
}

final class NotARector
{
    public function decorate(Node $node): void
    {
        $node->setAttribute('feature_set_marker', true);
    }
}
