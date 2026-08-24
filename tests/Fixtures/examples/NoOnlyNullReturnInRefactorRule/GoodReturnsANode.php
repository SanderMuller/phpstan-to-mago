<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/**
 * One `return` hands back a node, so the method does something and the rule stays silent. The other returns
 * `null`, which is what makes this a near miss rather than a class the guard never reaches.
 */
final class ReturnsANodeRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof Node\Scalar\String_) {
            return null;
        }

        return $node;
    }
}
