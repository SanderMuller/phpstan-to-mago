<?php

declare(strict_types=1);

namespace TraitDivergence;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

/**
 * Logs which class encloses every method PHPStan visits, and reports nothing.
 *
 * Written to a file rather than returned as findings because the tool wrapper this repository runs under
 * rewrites `phpstan analyse` output -- see {@see Subprocess}. A file
 * is the one channel neither wrapper touches.
 *
 * @implements Rule<ClassMethod>
 */
final class PhpstanProbe implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        file_put_contents(
            (string) getenv('TRAIT_PROBE_OUT'),
            sprintf("%s::%s\n", $scope->getClassReflection()?->getName() ?? '(none)', $node->name->name),
            FILE_APPEND,
        );

        return [];
    }
}
