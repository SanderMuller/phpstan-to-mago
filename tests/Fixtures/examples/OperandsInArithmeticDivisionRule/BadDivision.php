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
}
