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
 * `$type->isObject()->yes()`, the union question PHPStan asks of a whole type.
 *
 * Every atomic has to be an object, which is what `yes` means there: `Foo|null` is a `maybe` and is not one
 * here either. The runtime helper carries one divergence and states it — an unresolved reference does not
 * count, because `ReferenceTypeKind` is `Symbol`, `Member` or `Global` and the same atomic stands for a
 * global constant's type as for a class-like's.
 *
 * `NoJustPropertyAssignRule` is the corpus rule that asks it. It still refuses, in the docblock exemption
 * further down.
 *
 * @implements Rule<Expression>
 */
final class ObjectTypedAssignRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not assign an object here';

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

        if (! $scope->getType($expr->expr)->isObject()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.objectTypedAssign')
                ->build(),
        ];
    }
}
