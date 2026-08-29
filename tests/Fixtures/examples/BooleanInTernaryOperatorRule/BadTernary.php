<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadTernary
{
    /**
     * A string in a ternary condition.
     *
     * A plain `string`, for the reason the sibling `BooleanInIfConditionRule` pair gives: it reports under
     * every value of the six flags `RuleLevelHelper` reads, and the gate runs at level 0.
     */
    public function describe(string $name): string
    {
        return $name ? $name : 'anonymous';
    }
}
