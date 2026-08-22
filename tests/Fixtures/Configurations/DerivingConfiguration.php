<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Configurations;

/**
 * A configuration value object whose derived getters sit just outside the one recognised shape.
 *
 * The vendored packages give the shape that *is* recognised — `getDependencyTreeTypes() !== []` — and nothing
 * they ship comes close enough to it to prove the boundary. So the near misses live here: each would be
 * carried as an emptiness test if the recognition were one condition looser, and each would then answer a
 * different question than the code asks.
 */
final readonly class DerivingConfiguration
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private array $parameters
    ) {}

    /**
     * @return list<string>
     */
    public function getTypes(): array
    {
        return $this->parameters['types'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getTypesFor(string $kind): array
    {
        return $this->parameters[$kind] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getUndeclared(): array
    {
        return $this->parameters['undeclared'] ?? [];
    }

    /** The other polarity, and the one that decides which way the emitted comparison reads. */
    public function isEmpty(): bool
    {
        return $this->getTypes() === [];
    }

    /** Asks another object's getter, whose parameters this one does not hold. */
    public function isOtherSet(self $other): bool
    {
        return $other->getTypes() !== [];
    }

    /** Compares against a *populated* literal, so "is it empty" is not the question being asked. */
    public function isDefaultSet(): bool
    {
        return $this->getTypes() === ['default'];
    }

    /** Loose rather than identical: `[] == 0` in PHP, so this is not the emptiness test either. */
    public function isLooselyEmpty(): bool
    {
        return $this->getTypes() == [];
    }

    /**
     * Asks about a value it was handed, not about a parameter this object holds.
     *
     * @param list<string> $other
     */
    public function isEmptyList(array $other): bool
    {
        return $other === [];
    }

    /** The inner getter takes an argument, so which parameter it reads is not decided here. */
    public function isKindSet(string $kind): bool
    {
        return $this->getTypesFor($kind) !== [];
    }

    /** Recognised, but the parameter behind it is one no neon declares. */
    public function isUndeclaredSet(): bool
    {
        return $this->getUndeclared() !== [];
    }
}
