<?php

declare(strict_types=1);

namespace Examples\Operators;

final class BadDivision
{
    /**
     * A boolean on the left of a division.
     *
     * `bool` reports under every value of the six flags, where the other non-numeric operands do not:
     * `array`, `object` and plain `string` are silent in every configuration measured, because PHPStan
     * core already reports those and `OperatorRuleHelper` returns early rather than repeat it.
     */
    public function share(bool $enabled, int $total): float
    {
        return $enabled / $total;
    }

    /**
     * The same rule, written as a compound assignment, which is the dispatch's other arm.
     *
     * php-parser splits these into `BinaryOp\Div` and `AssignOp\Div` and the rule handles both in one
     * `if`/`elseif`. Mago spells them `Binary` and `Assignment`, so without this row the emitted plugin
     * could omit the `Assignment` target entirely and the pair would still pass — which it did, until this
     * was written.
     */
    public function accumulate(bool $enabled): float
    {
        $enabled /= 2;

        return $enabled;
    }

    /** The right-hand operand, so `->right` and `->expr` are read rather than only `->left` and `->var`. */
    public function scale(int $total, bool $enabled): float
    {
        return $total / $enabled;
    }
}
