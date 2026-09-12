<?php

declare(strict_types=1);

namespace Examples\BooleanAnd;

final class GoodAnd
{
    /** Booleans on both sides, which the rule allows. */
    public function both(bool $left, bool $right): bool
    {
        return $left && $right;
    }

    /** Comparisons, which are booleans. */
    public function compared(string $name, int $count): bool
    {
        return $name === '' && $count > 0;
    }

    /** The keyword spelling with booleans, so the operator alone is not what reports. */
    public function keyword(bool $left, bool $right): bool
    {
        return $left and $right;
    }
}
