<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadCondition
{
    /**
     * A string in an if condition.
     *
     * Chosen because it reports under every value of the six flags `RuleLevelHelper` reads, which the pair
     * has to be while the gate runs at level 0. A nullable condition would be the sharper case -- `?bool`
     * is silent at `checkNullables: false` and reports at true, measured -- but it cannot go in a pair
     * until the gate pins the flags on both sides.
     */
    public function describe(string $name): string
    {
        if ($name) {
            return $name;
        }

        return 'anonymous';
    }
}
