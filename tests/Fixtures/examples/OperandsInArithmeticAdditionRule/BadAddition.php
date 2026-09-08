<?php

declare(strict_types=1);

namespace Examples\Operators;

/**
 * A boolean operand on each side of `+`, and the two rows that pin the array guard to *both* sides.
 *
 * `array + array` is a valid union in PHP, so the rule declines when both operands are arrays. The guard
 * therefore needs one array and one non-array to fail — which is what `withAnArrayOnTheOtherSide()` and
 * `withAnArrayOnThisSide()` are: PHPStan reports the non-array side of each and says nothing about the array,
 * because an array is never a numeric-operand finding of this rule's own. Measured at this gate's flags.
 */
final class BadAddition
{
    public function left(bool $enabled, int $total): mixed
    {
        return $enabled + $total;
    }

    public function compound(bool $enabled): mixed
    {
        $enabled += 2;

        return $enabled;
    }

    public function right(int $total, bool $enabled): mixed
    {
        return $total + $enabled;
    }

    /** @param array<int, string> $rows */
    public function withAnArrayOnTheOtherSide(array $rows, bool $enabled): mixed
    {
        return $rows + $enabled;
    }

    /** @param array<int, string> $rows */
    public function withAnArrayOnThisSide(bool $enabled, array $rows): mixed
    {
        return $enabled + $rows;
    }
}
