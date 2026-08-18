<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;

/**
 * The guard of {@see TraitHelperRule}, in a trait.
 *
 * Real rule packages are shaped like this: the logic lives in a trait or an abstract base and the rule
 * itself is a shim. Inlining only same-class helpers refused all of them.
 */
trait DetectsStaticAccess
{
    private function isStaticAccess(Node\Expr\ClassConstFetch $node): bool
    {
        return $node->class instanceof Node\Name && $node->class->toString() === 'static';
    }
}
