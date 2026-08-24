<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * A helper that hands back a `?ClassReflection`, and the `instanceof` its caller guards with.
 *
 * Three corpus rules write this shape. Mago has no reflection object to stand in for one and needs none —
 * every question a rule asks of the handle takes the class name, so the handle *is* the name. That leaves
 * the `instanceof` asking whether the codebase knows the name, which is the `hasClass()` the helper guards
 * with, so it is emitted rather than folded away: whether that guard has already run is a fact about how
 * helpers are inlined, and a redundant test is cheap where a wrong assumption is not.
 *
 * @implements Rule<MethodCall>
 */
final class ReflectedMockedClassRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not mock a class the codebase knows';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if ($node->name->toString() !== 'createMock') {
            return [];
        }

        $mockedClassReflection = $this->matchMockedClassReflection($node, $scope);
        if (! $mockedClassReflection instanceof ClassReflection) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.reflectedMockedClass')
                ->build(),
        ];
    }

    private function matchMockedClassReflection(MethodCall $methodCall, Scope $scope): ?ClassReflection
    {
        $firstArg = $methodCall->getArgs()[0];
        $argType = $scope->getType($firstArg->value);

        if (! $argType instanceof ConstantStringType) {
            return null;
        }

        $mockedClass = $argType->getValue();
        if (! $this->reflectionProvider->hasClass($mockedClass)) {
            return null;
        }

        return $this->reflectionProvider->getClass($mockedClass);
    }
}
