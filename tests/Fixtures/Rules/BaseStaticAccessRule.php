<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;

/**
 * The guard of {@see ParentHelperRule}, in an abstract base.
 *
 * The other half of the shim shape: a base class holding the logic, subclassed once per node type.
 */
abstract class BaseStaticAccessRule
{
    final protected function isStaticAccess(Node\Expr\ClassConstFetch $node): bool
    {
        return $node->class instanceof Node\Name && $node->class->toString() === 'static';
    }
}
