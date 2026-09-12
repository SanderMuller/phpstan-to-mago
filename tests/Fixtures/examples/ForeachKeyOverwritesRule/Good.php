<?php

declare(strict_types=1);

namespace Examples\ForeachKey;

final class UnkeyedLoops
{
    /**
     * Both loops bind a value and no key, which is `keyVar === null` on php-parser and a
     * `ForeachValueTarget` on mago.
     *
     * The destructuring loop is here rather than in the bad file on purpose: it is the case where a port
     * that answered "has a key" from the presence of a child rather than from the target's kind would report,
     * because a destructured value has children of its own.
     *
     * @param array<int, int>        $counts
     * @param array<int, array{int, int}> $pairs
     */
    public function run(array $counts, array $pairs): int
    {
        $total = 0;
        foreach ($counts as $count) {
            $total += $count;
        }

        foreach ($pairs as [$left, $right]) {
            $total += $left + $right;
        }

        return $total;
    }
}
