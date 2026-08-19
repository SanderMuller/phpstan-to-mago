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
 * Derives a lookup table from a configured value in the constructor body.
 *
 * Translatable in principle — the derivation touches only configured values and literals — and not
 * translated yet, so the refusal says that rather than calling the property unknown.
 *
 * @implements Rule<FuncCall>
 */
final class DerivedPropertyRule implements Rule
{
    public const string ERROR_MESSAGE = 'Derived lookup';

    /** @var array<string, true> */
    private array $lookup;

    /**
     * @param list<string> $namespaces
     */
    public function __construct(
        private array $namespaces,
        private int $limit,
        private ReflectionProvider $reflectionProvider,
    ) {
        $this->lookup = array_fill_keys($namespaces, true);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->lookup === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.derived')
                ->build(),
        ];
    }
}
