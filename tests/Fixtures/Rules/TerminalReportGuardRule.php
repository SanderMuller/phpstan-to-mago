<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A guard that reports and exits, rather than one that declines.
 *
 * `if (COND) { return [<error>]; }` is how a rule reporting at most one thing writes its finding, and
 * nineteen rules across the installed packages write it. It was refused as a guard body that is not
 * `return []`, which named the statement rather than what it does — the sibling shape,
 * `if (COND) { $errors[] = <error>; }`, has been translated all along.
 *
 * Two of them here, because a rule with one such guard proves less than a rule whose second guard has to be
 * reached only when the first did not fire.
 *
 * @implements Rule<MethodCall>
 */
final class TerminalReportGuardRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if ($node->name->toString() === 'forbidden') {
            return [
                RuleErrorBuilder::message('This method is forbidden')
                    ->identifier('fixture.terminalReportGuard')
                    ->build(),
            ];
        }

        return [];
    }
}
