<?php

declare(strict_types=1);

namespace Examples\ForeachKey;

final class KeyedLoops
{
    /**
     * Two keyed loops, one of them destructuring its value.
     *
     * The second is the shape that separates a helper reading the key from one reading a fixed child
     * position: `ForeachKeyValueTarget` holds the key first and the value second, so a port reading position
     * zero for the value reports here and disagrees.
     *
     * @param array<string, array{int, int}> $pairs
     * @param array<string, int>             $counts
     */
    public function run(array $counts, array $pairs): int
    {
        $total = 0;
        foreach ($counts as $name => $count) {
            $total += strlen($name) + $count;
        }

        foreach ($pairs as $label => [$left, $right]) {
            $total += strlen($label) + $left + $right;
        }

        return $total;
    }
}
