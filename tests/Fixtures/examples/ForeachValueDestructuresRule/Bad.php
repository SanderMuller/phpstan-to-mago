<?php

declare(strict_types=1);

namespace Examples\ForeachValue;

final class DestructuringLoops
{
    /**
     * Two destructuring loops, one keyed and one not.
     *
     * Both are `ForeachValueTarget`/`ForeachKeyValueTarget` holding an array rather than a variable, and the
     * keyed one is what makes the position matter: reading the value at position zero would find the key
     * there, which *is* a plain variable, and the rule would fall silent on it.
     *
     * @param array<int, array{int, int}>    $pairs
     * @param array<string, array{int, int}> $labelled
     */
    public function run(array $pairs, array $labelled): int
    {
        $total = 0;
        foreach ($pairs as [$left, $right]) {
            $total += $left + $right;
        }

        foreach ($labelled as $label => [$first, $second]) {
            $total += strlen($label) + $first + $second;
        }

        return $total;
    }
}
