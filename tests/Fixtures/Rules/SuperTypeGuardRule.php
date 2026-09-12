<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `$container->isSuperTypeOf($input)->yes()`, which the SDK spells with the arguments the other way round.
 *
 * `TypeComparator::isContainedBy($input, $container)` is the same question, and it is reachable from a node
 * hook because `NodeAnalysisContext extends LifecycleContext`, which declares `public readonly TypeComparator
 * $types`. This repository recorded the capability as absent from the SDK for a while, on a grep that asked
 * for `contains` where the method is `isContainedBy`.
 *
 * Only the `yes` tail translates. The SDK answers a bool where PHPStan answers a trinary, so
 * `! isContainedBy()` is *maybe or no*, and reading it as `no` would claim a proof the comparator never gave.
 *
 * @implements Rule<Expression>
 */
final class SuperTypeGuardRule implements Rule
{
    public const string ERROR_MESSAGE = 'The assigned value does not widen the target';

    public function getNodeType(): string
    {
        return Expression::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $expr = $node->expr;

        if (! $expr instanceof Assign) {
            return [];
        }

        if (! $scope->getType($expr->var)->isSuperTypeOf($scope->getType($expr->expr))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.superTypeGuard')
                ->build(),
        ];
    }
}
