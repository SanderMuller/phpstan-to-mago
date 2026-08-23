<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gates on the enclosing class being of a given kind, the way `phpstan-phpunit`'s rules gate on `TestCase`.
 *
 * The body past the gate is deliberately nothing: what is being pinned is the gate.
 *
 * Three things at once, and all three used to refuse. The `=== null` cannot hold in a hook that fires on a
 * class member, so it folds with that as its reason. `->is()` is `isSubclassOf()` including the class itself,
 * which is what Mago's instance test already means — so it maps exactly rather than approximately. And a
 * predicate compared to `=== false` is a predicate: the boolean literal is what makes translating the left
 * side as one safe, since an expression compared to a boolean can only have been one.
 *
 * @implements Rule<ClassMethod>
 */
final class TestCaseOnlyRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not declare methods on this base';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();
        if ($classReflection === null || $classReflection->is(BaseFixtureCase::class) === false) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.testCaseOnly')
                ->build(),
        ];
    }
}
