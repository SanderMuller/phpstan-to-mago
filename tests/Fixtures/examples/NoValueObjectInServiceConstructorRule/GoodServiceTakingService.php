<?php

declare(strict_types=1);

namespace Examples\Services;

use Examples\ValueObject\Money;

final class Rounder
{
    public function round(int $cents): int
    {
        return $cents;
    }
}

/** A service taking a service, and an untyped parameter, neither of which the rule is about. */
final class Formatter
{
    public function __construct(private readonly Rounder $rounder, private $options = null) {}

    /** A value object as a *method* argument, which is what the rule asks for. */
    public function format(Money $money): string
    {
        return '';
    }
}
