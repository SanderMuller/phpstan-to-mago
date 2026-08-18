<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Calls a helper nothing in the hierarchy declares. Refusing by name is the point.
 *
 * @implements Rule<ClassConstFetch>
 */
final class MissingHelperRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassConstFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->missingHelper($node)) {
            return [];
        }

        return [];
    }
}
