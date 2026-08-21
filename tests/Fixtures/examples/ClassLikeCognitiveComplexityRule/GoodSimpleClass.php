<?php

declare(strict_types=1);

namespace Examples\Complexity;

/**
 * A class scoring *exactly* the configured limit, which is the only shape that makes this pair sensitive.
 *
 * The rule declines on `<=`, so a class at the limit reports nothing and a class one point over reports. Three
 * sibling `if`s at the top level of one method is 3: each is one increment, and none earns a nesting bonus,
 * because the model pays only for going *deeper* than the last construct it weighted — the second and third
 * sit at the same level as the first.
 *
 * Written this way deliberately. An earlier version scored 1 against a limit of 3, and a one-point error in
 * the nesting weight moved it to 2 and the bad example from 4 to 7 — both still on their original sides of the
 * limit, so the pair passed a model that was wrong. A threshold rule is only tested at its threshold.
 */
final class GoodSimpleClass
{
    public function label(int $number): string
    {
        if ($number > 0) {
            return 'positive';
        }

        if ($number < 0) {
            return 'negative';
        }

        if ($number === 0) {
            return 'zero';
        }

        return 'other';
    }
}
