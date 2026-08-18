<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<FuncCall>
 */
final class ConcreteRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<never>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }
}
