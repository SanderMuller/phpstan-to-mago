<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/**
 * Every `return` in `refactor()` returns `null`, so the method can never change anything and the rule reports.
 */
final class AlwaysNullRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Node\Scalar\String_) {
            return null;
        }

        return null;
    }
}
