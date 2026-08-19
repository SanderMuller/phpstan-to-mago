<?php

declare(strict_types=1);

namespace Fixture\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Resolves a class through a PHPStan service and stores the reflection.
 *
 * The obstacle is the service, not the derivation: no worker can supply a `ClassReflection`. Naming the
 * derivation instead would point at the wrong thing to fix.
 *
 * @implements Rule<FuncCall>
 */
final class DerivedFromServiceRule implements Rule
{
    public const string ERROR_MESSAGE = 'Derived from a service';

    private ?ClassReflection $resolved;

    /**
     * @param list<string> $namespaces
     */
    public function __construct(
        private array $namespaces,
        private int $limit,
        private ReflectionProvider $reflectionProvider,
    ) {
        $this->resolved = $reflectionProvider->hasClass('Acme\Thing')
            ? $reflectionProvider->getClass('Acme\Thing')
            : null;
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->resolved instanceof ClassReflection) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.derivedFromService')
                ->build(),
        ];
    }
}
