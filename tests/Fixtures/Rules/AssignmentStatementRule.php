<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule hooked on the statement wrapper rather than on the expression inside it.
 *
 * Three pieces meet here and none of them worked before: the hook for `Stmt\Expression`, which Mago spells
 * `ExpressionStatement`; `->expr`, the one expression the wrapper holds; and `instanceof Assign`, which Mago
 * answers from the `Assignment` kind it gives every compound spelling alike.
 *
 * `NoJustPropertyAssignRule` is the corpus rule with this head. It still refuses, further in — the point of
 * the fixture is that the head is now translatable, not that the rule is.
 *
 * @implements Rule<Expression>
 */
final class AssignmentStatementRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not assign to $result';

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

        if (! $expr->var instanceof Variable) {
            return [];
        }

        if ($expr->var->name !== 'result') {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.assignmentStatement')
                ->build(),
        ];
    }
}
