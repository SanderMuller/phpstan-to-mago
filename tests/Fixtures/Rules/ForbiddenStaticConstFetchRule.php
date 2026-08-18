<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A guard chain: the shape most rules have, and the one the transpiler handles best.
 *
 * @implements Rule<ClassConstFetch>
 */
final class ForbiddenStaticConstFetchRule implements Rule
{
    public const string ERROR_MESSAGE = 'Avoid static access of constants';

    public function getNodeType(): string
    {
        return ClassConstFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Node\Name) {
            return [];
        }

        if ($node->class->toString() !== 'static') {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.forbiddenStaticConstFetch')
                ->build(),
        ];
    }
}
