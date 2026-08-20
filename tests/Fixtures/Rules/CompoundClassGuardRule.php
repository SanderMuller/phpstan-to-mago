<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class test compounded with another condition, which is the shape that made the target set wrong twice.
 *
 * `isClass()` is true for a class and false for an enum or an interface, and PHPStan's `InClassNode` fires for
 * all three. So this rule exempts *abstract classes* and reports everything else — including enums.
 *
 * Folding `isClass()` to "always true" leaves the guard reading `isAbstract()` alone, and recording the fold as
 * a narrowing drops the enum and interface targets. Either way the port goes silent on an enum the rule
 * reports. The predicate is asked at runtime instead, and the example pair is what shows the difference.
 *
 * @implements Rule<InClassNode>
 */
final class CompoundClassGuardRule implements Rule
{
    public const string ERROR_MESSAGE = 'Declaration is not allowed here';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if ($classReflection->isClass() && $classReflection->isAbstract()) {
            return [];
        }

        if (! str_contains($classReflection->getDisplayName(), 'Reported')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.compoundClassGuard')
                ->build(),
        ];
    }
}
