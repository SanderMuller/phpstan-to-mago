<?php

declare(strict_types=1);

namespace Examples\Complexity;

/**
 * A method scoring *exactly* the limit, which is the only shape that makes this pair sensitive.
 *
 * The rule declines on `<=`, so at the limit it reports nothing and one point over it reports. Two sibling
 * `if`s at a method's top level are 2: one increment each, and neither earns a nesting bonus, because the
 * model pays only for going deeper than the last construct it weighted.
 *
 * A closure is here too, and scores nothing on its own: `Closure` raises the nesting level but is not an
 * increment, and its body holds none. A port that counted the declaration itself would report this file.
 */
final class GoodAtTheLimit
{
    public function label(int $number): string
    {
        if ($number > 0) {
            return 'positive';
        }

        if ($number < 0) {
            return 'negative';
        }

        return 'zero';
    }

    public function wrapped(): callable
    {
        return static function (): int {
            return 1;
        };
    }
}
