<?php

declare(strict_types=1);

namespace Examples\Rectors;

use PhpParser\Node;
use Rector\Contract\Rector\RectorInterface;

final class GoodNoNodeOnProperty extends AbstractExampleRector implements RectorInterface
{
    private int $seen = 0;

    private ?Node $unused = null;

    public function refactor(Node $node): ?Node
    {
        // Not a node: the assigned value's type is what the rule asks about, and the shape of the assignment
        // is otherwise identical to the bad example's.
        $this->seen = 1;

        // A local, not a property, so there is nothing parked on the object.
        $local = $node;

        return $local;
    }
}
