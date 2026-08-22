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
 * Compares how many arguments a call was written with, numerically.
 *
 * `count($node->getArgs()) === 0` already emitted; the same expression compared with `<` refused, which read
 * as the vocabulary not covering argument counts at all. It covers them — an argument list is a node whose
 * `Argument` children are the arguments, and one helper answers both comparisons.
 *
 * @implements Rule<FuncCall>
 */
final class ArgumentCountRule implements Rule
{
    public const string ERROR_MESSAGE = 'Call takes at least two arguments';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if ($node->name->toString() !== 'needsTwo') {
            return [];
        }

        if (count($node->getArgs()) >= 2) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.argumentCount')
                ->build(),
        ];
    }
}
