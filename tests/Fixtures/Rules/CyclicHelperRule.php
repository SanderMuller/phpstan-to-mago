<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Two helpers that call each other, so inlining never reaches a decision.
 *
 * The shape the depth cap was written for, and the only one it should refuse. Indirect rather than direct,
 * because a helper whose whole body is a call to itself is caught earlier as a forward to its own name — the
 * cycle worth guarding against is the one that needs a stack to see.
 *
 * @implements Rule<FuncCall>
 */
final class CyclicHelperRule implements Rule
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

        if (! $this->ping($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.cyclicHelper')
                ->build(),
        ];
    }

    private function ping(FuncCall $node): bool
    {
        return $this->pong($node);
    }

    private function pong(FuncCall $node): bool
    {
        return $this->ping($node);
    }
}
