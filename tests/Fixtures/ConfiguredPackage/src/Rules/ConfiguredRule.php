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
 * Reads a property the package wires as a configured value.
 *
 * @implements Rule<FuncCall>
 */
final class ConfiguredRule implements Rule
{
    public const string ERROR_MESSAGE = 'Configured namespaces are %s';

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
        if ($this->namespaces === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.configured')
                ->build(),
        ];
    }
}
