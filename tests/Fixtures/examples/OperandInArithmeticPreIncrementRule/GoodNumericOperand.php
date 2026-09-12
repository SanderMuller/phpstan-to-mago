<?php

declare(strict_types=1);

namespace Examples\PreIncrement;

/** A number, and the union of the two number kinds. */
final class NumericOperand
{
    public function numbers(int $count, float $ratio, int|float $either): void
    {
        ++$count;
        ++$ratio;
        ++$either;
    }
}
