<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Two more shapes the flag check has to reach, taken from a 4225-file corpus.
 *
 * Both were written expecting the original to decline — a bare bool on a method reached through an
 * interface-typed arrow-function parameter, and one inside a string interpolation. Real PHPStan reports both,
 * so the guess was wrong and they belong here rather than in the good pair. Kept because they are shapes
 * nothing else covers, and because the port has to keep agreeing on them.
 *
 * What the corpus disagreement actually turned out to be is not here: it needs an *intersection* receiver
 * type, which PHPStan infers from generics and mago does not. See `internal/pricing-items-2-and-3.md`.
 */
interface MapsToQuery
{
    public function toQuery(bool $isInPackage): string;
}

final class BadFlagShapes
{
    /**
     * @param list<MapsToQuery> $calculators
     *
     * @return list<string>
     */
    public function throughAnArrowFunction(array $calculators): array
    {
        return array_map(
            static fn (MapsToQuery $calculator): string => $calculator->toQuery(true),
            $calculators,
        );
    }

    public function throughAnInterpolation(): string
    {
        return "{$this->providerName(true)} error";
    }

    public function providerName(bool $short = false): string
    {
        return $short ? 'x' : 'xx';
    }
}
