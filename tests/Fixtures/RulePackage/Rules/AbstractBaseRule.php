<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

abstract class AbstractBaseRule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<never>
     */
    abstract public function processNode(Node $node, Scope $scope): array;
}
