<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/** The direct call the rule asks for: the receiver is `$this`, not a property on it. */
final class DirectIsNameRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        return $this->isName($node, 'foo') ? $node : null;
    }
}

/** The abstract base of a family, which the rule skips: it is where such a service legitimately lives. */
abstract class AbstractSharedRector extends AbstractRector
{
    public function __construct(protected readonly NameResolver $nodeNameResolver) {}

    public function shared(Node $node): bool
    {
        return $this->nodeNameResolver->isName($node, 'foo');
    }
}

/** Not a Rector rule at all, so the property fetch is none of the rule's business. */
final class PlainService
{
    public function __construct(private readonly NameResolver $nodeNameResolver) {}

    public function check(Node $node): bool
    {
        return $this->nodeNameResolver->isName($node, 'foo');
    }
}
