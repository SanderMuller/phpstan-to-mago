<?php

declare(strict_types=1);

namespace Examples\Operators;

/**
 * Numeric operands, and an operator the rule declines.
 *
 * `*` beside `**` and `**` beside `*`, on purpose: the guard compares the operator's text exactly, and
 * these two are the pair a prefix match would confuse.
 */
final class GoodMultiplication
{
    public function numeric(int $enabled, int $total): mixed
    {
        return $enabled * $total;
    }

    public function compound(int $count): mixed
    {
        $count *= 2;

        return $count;
    }

    public function otherOperator(bool $enabled): mixed
    {
        $sum = $enabled ** 1;
        $sum **= 1;

        return $sum;
    }
}
