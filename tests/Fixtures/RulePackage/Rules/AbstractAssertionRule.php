<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * A base that carries the `Rule` interface for its subclasses and declares no `getNodeType()`.
 *
 * `phpat` splits a rule this way: the interface arrives through the base, the node type through a trait,
 * and the concrete class states neither.
 *
 * @implements Rule<Node>
 */
abstract class AbstractAssertionRule implements Rule
{
    /**
     * @return list<never>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }
}
