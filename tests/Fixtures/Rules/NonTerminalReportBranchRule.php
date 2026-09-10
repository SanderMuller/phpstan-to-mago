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
 * that looks exactly like a correct one.
 *
 * **The port refused for that reason and now emits, because the fold is no longer the only handling.**
 * `translateConditionalReport()` emits a real `if-open`/`block-close` with the branch's own exit inside it,
 * so what follows the branch still runs -- which is the soundness argument `isNestedConditionalReport()`
 * already carried for a nested report and which reaches this shape once a branch is accepted before the
 * report. The superseded sentence is kept above rather than edited away: it was true of the only translation
 * available when it was written, and the reason it stopped being true is the point.
 *
 * So this rule now pins the emission rather than the refusal, and the pair is what makes that a measurement
 * instead of a reading. `BadBothBranches.php` calls both `->first()` and `->second()`: PHPStan reports one
 * finding from each branch, and the port agrees, so the second check is reachable. One finding there is what
 * a lost per-branch block looks like, and agreement on one finding would pass a comparison while the second
 * check was gone -- which is why the count is checked and not only the agreement.
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
