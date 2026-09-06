<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class reflection reached through PHPStan's hatch to the native `ReflectionClass`.
 *
 * For the questions a rule asks through that hop — `isInterface()` here, `getName()` and `isAnonymous()` in
 * the corpus — the two objects answer the same thing, so the hop is the identity and the descriptor passes
 * through unchanged. `RequireParentConstructCallRule` writes it and refused on the hop rather than on
 * anything it asks.
 *
 * The refusal is not removed, only moved to where it belongs: a question the native object answers and the
 * reflection does not still refuses, one call later and under its own name.
 *
 * The `isInClass()` guard is the corpus shape and it is load-bearing here too — `getClassReflection()` is
 * nullable, and without it the *rule* does not type-check, which the emitted-plugin analysis catches.
 *
 * @implements Rule<ClassMethod>
 */
final class NativeReflectionHopRule implements Rule
{
    public const string ERROR_MESSAGE = 'Declare this method on a class rather than an interface';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $scope->isInClass()) {
            return [];
        }

        $classReflection = $scope->getClassReflection()->getNativeReflection();

        if (! $classReflection->isInterface()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.nativeReflectionHop')
                ->build(),
        ];
    }
}
