<?php

declare(strict_types=1);

namespace Fixtures\LoopReports\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The shape the loop-escape check exists for: guards inside the loop, the report after it.
 *
 * The helper decides and builds the finding, so its guards are inlined where it is called — inside the
 * `foreach` — while the finding itself is the rule's own trailing report, which the emitter appends after
 * the loop has closed. The message reads `$name`, and only the loop binds it.
 *
 * Emitted rather than refused this would load and misbehave: the escaped read is a bare snake_case
 * identifier, which PHP takes for a constant, so the plugin reports under an undefined name where the
 * list was empty and not at all where it was not.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
class ReportsAfterTheLoopRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($scope->getType($node->getArgs()[0]->value)->getConstantStrings() as $constantString) {
            $error = $this->checkName($constantString->getValue());
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function checkName(string $name): ?IdentifierRuleError
    {
        if ($name === 'forbidden') {
            return RuleErrorBuilder::message(sprintf('Method %s() is forbidden.', $name))
                ->identifier('fixture.reportsAfterTheLoop')
                ->build();
        }

        return null;
    }
}
