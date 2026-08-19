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
 * Derives a property by calling one of its own methods.
 *
 * A method call could depend on anything, so the derivation is outside the set the generated constructor
 * carries verbatim. The refusal has to say that rather than blaming the package.
 *
 * @implements Rule<FuncCall>
 */
final class ImpureDerivationRule implements Rule
{
    public const string ERROR_MESSAGE = 'Impure derivation';

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
        $this->lookup = $this->buildLookup($namespaces);
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
                ->identifier('fixture.impureDerivation')
                ->build(),
        ];
    }

    /**
     * @param list<string> $namespaces
     *
     * @return array<string, true>
     */
    private function buildLookup(array $namespaces): array
    {
        return array_fill_keys($namespaces, true);
    }
}
