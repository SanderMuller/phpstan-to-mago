<?php

declare(strict_types=1);

namespace Examples\Operators;

/**
 * Numeric operands, and an operator the rule declines.
 *
 * `+` is an operator this rule does not read, so registering the node kinds without testing the
 * token would report it.
 */
final class GoodSubtraction
{
    public function numeric(int $enabled, int $total): mixed
    {
        return $enabled - $total;
    }

    public function compound(int $count): mixed
    {
        $count -= 2;

        return $count;
    }

    public function otherOperator(bool $enabled): mixed
    {
        $sum = $enabled + 1;
        $sum += 1;

        return $sum;
    }
}
