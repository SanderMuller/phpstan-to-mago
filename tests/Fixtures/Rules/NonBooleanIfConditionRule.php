<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\If_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * An `if` condition's type, rendered into the message.
 *
 * The three capabilities the boolean-condition family needs, in the one shape that can be gated: a hook on a
 * statement, its `->cond` child, and the renderer. Five corpus rules ask exactly this and then hand the
 * condition to `BooleanRuleHelper`, whose answer comes from PHPStan's `RuleLevelHelper` and is
 * level-dependent — so they stay refused there and this fixture is what proves the parts before it.
 *
 * @implements Rule<If_>
 */
final class NonBooleanIfConditionRule implements Rule
{
    public function getNodeType(): string
    {
        return If_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $conditionType = $scope->getType($node->cond);

        if ($conditionType->isBoolean()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Only booleans are allowed in an if condition, %s given.',
                $conditionType->describe(VerbosityLevel::typeOnly()),
            ))->identifier('fixture.nonBooleanIfCondition')->build(),
        ];
    }
}
