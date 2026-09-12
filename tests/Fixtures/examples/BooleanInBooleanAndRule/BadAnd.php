<?php

declare(strict_types=1);

namespace Examples\BooleanAnd;

final class BadAnd
{
    /** A non-boolean on the left, which the rule reports as `booleanAnd.leftNotBoolean`. */
    public function left(string $name, bool $flag): bool
    {
        return $name && $flag;
    }

    /** A non-boolean on the right, under the other identifier. */
    public function right(bool $flag, string $name): bool
    {
        return $flag && $name;
    }

    /**
     * Both sides at once, which is one node reporting twice under two identifiers.
     *
     * The reason this file has it rather than only one of the two: the port emits a `report()` per side, and a
     * pair that only ever fails on one side would pass with the second report missing.
     */
    public function both(string $left, string $right): bool
    {
        return $left && $right;
    }

    /**
     * The keyword spelling, and **both tools are silent on it** -- measured, not assumed.
     *
     * Kept because a shared silence is still agreement and the gate checks that, and written up because it is
     * *not* the control I first wrote it as. I claimed it separated `&&` from `and` and it does not: with
     * this row present the `booleanAnd`/`logicalAnd` branch could be a constant and the pair would stay green.
     * Why PHPStan reports nothing here under the gate's configuration is untraced, so the identifier branch
     * is an unexercised path rather than a covered one.
     *
     * The file is in pint's `notPath`, because `logical_operators` rewrites `and` and the row would go with
     * it.
     */
    public function keyword(string $name, bool $flag): bool
    {
        if ($name and $flag) {
            return true;
        }

        return false;
    }
}
