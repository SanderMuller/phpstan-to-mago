<?php

declare(strict_types=1);

namespace Examples\ArithmeticPlus;

/** A number, and the two shapes of it PHPStan accepts without narrowing. */
final class NumericOperand
{
    public function numbers(int $count, float $ratio, int|float $either): float
    {
        return +$count + +$ratio + +$either;
    }
}
