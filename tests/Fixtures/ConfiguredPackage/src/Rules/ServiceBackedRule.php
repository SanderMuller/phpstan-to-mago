<?php

declare(strict_types=1);

namespace Fixture\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reads a property holding a PHPStan service.
 *
 * @implements Rule<FuncCall>
 */
final class ServiceBackedRule implements Rule
{
    public const string ERROR_MESSAGE = 'Service backed';

    /**
     * @param list<string> $namespaces
     */
    public function __construct(
        private array $namespaces,
        private int $limit,
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // `hasClass()` now translates onto the codebase, so the obstacle has to be something that does not:
        // a `ClassReflection` handed back for the rule to interrogate has no equivalent a worker can supply.
        if ($this->reflectionProvider->getClass('Acme\Thing')->isFinal()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.serviceBacked')
                ->build(),
        ];
    }
}
