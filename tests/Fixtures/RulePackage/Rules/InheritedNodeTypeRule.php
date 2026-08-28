<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Rules;

use Fixtures\RulePackage\Traits\ProvidesNodeType;

/**
 * A rule that states nothing about itself: no `implements` clause, no `getNodeType()`.
 *
 * This is the shape two of `phpat`'s 59 rules are written in, and the walk missed both. Nothing in the
 * file names PHPStan at all — the interface comes from the base and the node type from the trait.
 */
final class InheritedNodeTypeRule extends AbstractAssertionRule
{
    use ProvidesNodeType;
}
