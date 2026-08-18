<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A loop that reports from inside it, and a formatted message.
 *
 * @implements Rule<ClassConst>
 */
final class UppercaseConstantRule implements Rule
{
    public const string ERROR_MESSAGE = 'Constant "%s" must be uppercase';

    public function getNodeType(): string
    {
        return ClassConst::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->consts as $const) {
            $constantName = (string) $const->name;
            if (strtoupper($constantName) === $constantName) {
                continue;
            }

            return [
                RuleErrorBuilder::message(sprintf(self::ERROR_MESSAGE, $constantName))
                    ->identifier('fixture.uppercaseConstant')
                    ->build(),
            ];
        }

        return [];
    }
}
