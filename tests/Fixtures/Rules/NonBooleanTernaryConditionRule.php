<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Ternary;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * The same question on an *expression* node, which is the other of the two hook paths.
 *
 * `If_` and this cover both: a statement whose condition is its first `Expression` child, and an expression
 * whose condition is the first of three. The four remaining condition hooks — `elseif`, `while`, `do-while`,
 * `switch` — take the statement path with the same navigation, and were probed for both firing and child
 * position; no rule emits through them yet, so none of them is exercised here.
 *
 * @implements Rule<Ternary>
 */
final class NonBooleanTernaryConditionRule implements Rule
{
    public function getNodeType(): string
    {
        return Ternary::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $conditionType = $scope->getType($node->cond);

        if ($conditionType->isBoolean()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Only booleans are allowed in a ternary condition, %s given.',
                $conditionType->describe(VerbosityLevel::typeOnly()),
            ))->identifier('fixture.nonBooleanTernaryCondition')->build(),
        ];
    }
}
