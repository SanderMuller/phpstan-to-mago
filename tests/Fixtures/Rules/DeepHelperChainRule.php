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
 * A predicate reached through six helpers, each of which decides nothing on its own.
 *
 * Deeper than the flat cap of 4 that used to guard inlining. That cap was written as a recursion guard and
 * worked as one by accident, so a chain like this — long, but terminating — refused with "nests deeper than
 * 4", a message about the tool's own arithmetic. `hihaho/phpstan-rules` v3.15.2 added one level to an
 * existing chain and cost two rules that way.
 *
 * @implements Rule<FuncCall>
 */
final class DeepHelperChainRule implements Rule
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

        if (! $this->levelOne($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.deepHelperChain')
                ->build(),
        ];
    }

    private function levelOne(FuncCall $node): bool
    {
        return $this->levelTwo($node);
    }

    private function levelTwo(FuncCall $node): bool
    {
        return $this->levelThree($node);
    }

    private function levelThree(FuncCall $node): bool
    {
        return $this->levelFour($node);
    }

    private function levelFour(FuncCall $node): bool
    {
        return $this->levelFive($node);
    }

    private function levelFive(FuncCall $node): bool
    {
        return $this->levelSix($node);
    }

    private function levelSix(FuncCall $node): bool
    {
        return $node->name instanceof Name && $node->name->toString() === 'forbidden';
    }
}
