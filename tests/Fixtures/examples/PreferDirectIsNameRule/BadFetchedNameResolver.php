<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

final class NameResolver
{
    public function isName(Node $node, string $name): bool
    {
        return false;
    }
}

/** Reaches the resolver through a property instead of using the method `AbstractRector` already gives it. */
final class FetchedNameResolverRector extends AbstractRector
{
    public function __construct(private readonly NameResolver $nodeNameResolver) {}

    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($this->nodeNameResolver->isName($node, 'foo')) {
            return $node;
        }

        return null;
    }
}
