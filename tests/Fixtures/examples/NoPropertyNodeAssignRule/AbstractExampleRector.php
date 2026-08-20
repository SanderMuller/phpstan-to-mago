<?php

declare(strict_types=1);

namespace Examples\Rectors;

use PhpParser\Node;

/**
 * The methods `RectorInterface` requires, so an example can implement it without repeating six stubs.
 *
 * Not itself a `RectorInterface`, so the rule's hierarchy guard is answered by the example classes rather
 * than by this one.
 */
abstract class AbstractExampleRector
{
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function beforeTraverse(array $nodes): ?array
    {
        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        return null;
    }

    public function afterTraverse(array $nodes): ?array
    {
        return null;
    }
}
