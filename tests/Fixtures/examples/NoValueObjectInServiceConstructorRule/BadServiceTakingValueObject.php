<?php

declare(strict_types=1);

namespace Examples\Services;

use Examples\ValueObject\Money;

/** A service holding a value object, which the rule asks to be a method argument instead. */
final class PriceCalculator
{
    public function __construct(private readonly Money $money) {}
}
