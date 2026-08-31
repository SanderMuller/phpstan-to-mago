<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/**
 * Every `return` in `refactor()` returns `null`, so the method can never change anything and the rule reports.
 *
 * One of them is written `NULL`. The rule folds the case — `->toLowerString() !== 'null'` — and the port did
 * not, so a `refactor()` whose only returns were written that way was reported by PHPStan and missed here.
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
            return NULL;
        }

        return null;
    }
}
