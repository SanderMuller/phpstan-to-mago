<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\CollidingPackage\ShouldBeUppercase;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * One half of a pair that would be written to the same file.
 *
 * Both are ordinary rules that emit on their own. Only the short name they share makes them a collision,
 * which is the point: the guard has to be what refuses them, not anything about their bodies.
 *
 * @implements Rule<ClassConst>
 */
final class NamedConstantRule implements Rule
{
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
                RuleErrorBuilder::message(sprintf('Constant "%s" must be uppercase', $constantName))
                    ->identifier('fixture.namedConstant')
                    ->build(),
            ];
        }

        return [];
    }
}
