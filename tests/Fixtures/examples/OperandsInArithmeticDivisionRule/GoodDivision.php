<?php

declare(strict_types=1);

namespace Examples\Operators;

final class GoodDivision
{
    public function share(int $enabled, int $total): float
    {
        return $enabled / $total;
    }

    /** The compound arm with numeric operands: the control for `accumulate()` next door. */
    public function accumulate(int $count): float
    {
        $count /= 2;

        return $count;
    }

    /**
     * An operator the rule does not read, on both spellings.
     *
     * The dispatch declines everything that is not `/` or `/=`, and the emitted guard is an operator test
     * rather than a node-kind test — so a `Binary` and an `Assignment` carrying `+` and `+=` are what proves
     * the guard reads the token. Registering the kinds without testing the operator would report these.
     */
    public function otherOperators(bool $enabled): float
    {
        $sum = $enabled + 1;
        $sum += 1;

        return $sum;
    }
}
