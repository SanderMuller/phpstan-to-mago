<?php

declare(strict_types=1);

namespace Examples\Rectors;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use Rector\Contract\Rector\RectorInterface;

/**
 * A rector that parks a node on a property.
 *
 * The rule gates on the enclosing class descending from `RectorInterface` and on the assigned value's
 * *inferred type* being a php-parser node — the second is the question that needs a sub-expression's type, so
 * the vendored hierarchy has to be resolvable to both tools for the pair to mean anything.
 */
final class BadNodeOnProperty extends AbstractExampleRector implements RectorInterface
{
    private ?Node $current = null;

    public function refactor(Node $node): ?Node
    {
        $this->current = new Variable('value');

        return null;
    }
}
