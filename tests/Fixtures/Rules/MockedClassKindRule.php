<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The two questions a mocking rule asks: which literal strings a type names, and what kind of class each is.
 *
 * Both are plural-shaped in PHPStan and neither had a translation. `getConstantStrings()` returns a list —
 * a union of literal strings names more than one — and the rules walk it and act per element. The kind
 * questions are asked of `getClass(<a value>)`, which is a class the plugin only knows while it runs, so they
 * cannot be folded from which hook fired the way a declaration hook's own can.
 *
 * Reports a mock of a *concrete* class, which is the inverse of what the corpus rules skip. Written that way
 * so the pair disagrees on the predicates rather than on the guard around them: an abstract class and an
 * interface both have to be silent, and both have to reach the predicate to be.
 *
 * @implements Rule<MethodCall>
 */
final class MockedClassKindRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not mock a concrete class';

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

        $firstArg = $node->getArgs()[0];
        $mockedType = $scope->getType($firstArg->value);

        foreach ($mockedType->getConstantStrings() as $constantStringType) {
            if (! $this->reflectionProvider->hasClass($constantStringType->getValue())) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($constantStringType->getValue());
            if ($classReflection->isAbstract()) {
                continue;
            }

            if ($classReflection->isInterface()) {
                continue;
            }

            return [
                RuleErrorBuilder::message(self::ERROR_MESSAGE)
                    ->identifier('fixture.mockedClassKind')
                    ->build(),
            ];
        }

        return [];
    }
}
