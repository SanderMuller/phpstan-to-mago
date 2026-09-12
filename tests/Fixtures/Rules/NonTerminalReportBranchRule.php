<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The branch shape `DynamicCallOnStaticMethodsRule` needed, written where it must *not* be taken.
 *
 * That rule's reporting branch holds an assignment, an exiting guard and a report, and the port folds it into
 * the guard chain around it — a `return []` inside the branch becoming the plugin's one exit. That is only
 * sound while nothing follows the branch, because a plugin has no way to leave a block and carry on.
 *
 * Here something does follow it. Folded anyway, the second check below would never run: the plugin would
 * return on every call whose name is not `first`, and a reader of the emitted file would see a guard chain
 * that looks exactly like a correct one. So the port refuses instead, and this rule is the record that it
 * does — no corpus rule has this shape, so without it the precondition is a branch nothing takes.
 *
 * @implements Rule<MethodCall>
 */
final class NonTerminalReportBranchRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        if ($node->name->toString() === 'first') {
            $receiver = $node->var;
            if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'exempt') {
                return [];
            }

            return [
                RuleErrorBuilder::message('First check fired')
                    ->identifier('fixture.nonTerminalReportBranch')
                    ->build(),
            ];
        }

        if ($node->name->toString() === 'second') {
            return [
                RuleErrorBuilder::message('Second check fired')
                    ->identifier('fixture.nonTerminalReportBranch')
                    ->build(),
            ];
        }

        return [];
    }
}
