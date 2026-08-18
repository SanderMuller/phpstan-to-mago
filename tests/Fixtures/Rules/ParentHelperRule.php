<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The same decision again, reached through a helper declared in a parent class.
 *
 * @implements Rule<ClassConstFetch>
 */
final class ParentHelperRule extends BaseStaticAccessRule implements Rule
{
    public const string ERROR_MESSAGE = 'Avoid static access of constants';

    public function getNodeType(): string
    {
        return ClassConstFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isStaticAccess($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.parentHelper')
                ->build(),
        ];
    }
}
