<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Assigns from a helper that decides nothing and forwards to one that does.
 *
 * The shim shape the positional-flag rules use. Without following the forward, the shim's own name is what
 * refuses — for not building an error it was never going to build.
 *
 * @implements Rule<FuncCall>
 */
final class ForwardingHelperRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not call forbidden()';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $error = $this->errorFor($node);

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }

    private function errorFor(FuncCall $node): ?IdentifierRuleError
    {
        return $this->buildError($node);
    }

    private function buildError(FuncCall $node): ?IdentifierRuleError
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        if ($node->name->toString() !== 'forbidden') {
            return null;
        }

        return RuleErrorBuilder::message(self::ERROR_MESSAGE)
            ->identifier('fixture.forwardingHelper')
            ->build();
    }
}
