<?php

declare(strict_types=1);

namespace Examples\Operators;

/**
 * Numeric operands, an operator the rule declines, and the array guard actually firing.
 *
 * `bothArrays()` and `bothArraysCompound()` are what the guard exists for, and they are the control for the
 * two mixed rows in `BadAddition`: drop the guard and these two report, keep it and they do not.
 *
 * `otherOperator()` uses `-`, which this rule declines, so registering the node kinds without reading the
 * operator token would report it.
 */
final class GoodAddition
{
    public function numeric(int $enabled, int $total): mixed
    {
        return $enabled + $total;
    }

    public function compound(int $count): mixed
    {
        $count += 2;

        return $count;
    }

    /**
     * @param array<int, string> $rows
     * @param array<int, string> $more
     */
    public function bothArrays(array $rows, array $more): mixed
    {
        return $rows + $more;
    }

    /**
     * @param array<int, string> $rows
     * @param array<int, string> $more
     */
    public function bothArraysCompound(array $rows, array $more): mixed
    {
        $rows += $more;

        return $rows;
    }

    public function otherOperator(bool $enabled): mixed
    {
        $difference = $enabled - 1;
        $difference -= 1;

        return $difference;
    }
}
