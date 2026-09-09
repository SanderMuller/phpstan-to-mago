<?php

declare(strict_types=1);

namespace Fixtures\LoopReports\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The control beside {@see ReportsAfterTheLoopRule}, varying one axis: where the report is built.
 *
 * Same node type, same loop, same iterable, same message and identifier, and the message still reads the
 * loop's binding. The only difference is that this rule builds the finding inside the loop body, so the
 * report is emitted there and the binding is in scope.
 *
 * It must keep emitting. A check that refused this one too would be refusing every rule that reports from
 * inside a loop, which is the common shape rather than the defect -- and a refusal of the pair's first row
 * proves nothing on its own, because a check that refuses everything refuses that row as well.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
class ReportsInsideTheLoopRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($scope->getType($node->getArgs()[0]->value)->getConstantStrings() as $constantString) {
            if ($constantString->getValue() !== 'forbidden') {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf('Method %s() is forbidden.', $constantString->getValue()))
                ->identifier('fixture.reportsInsideTheLoop')
                ->build();
        }

        return $errors;
    }
}
