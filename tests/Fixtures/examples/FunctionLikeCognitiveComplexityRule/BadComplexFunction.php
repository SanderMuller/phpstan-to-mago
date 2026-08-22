<?php

declare(strict_types=1);

namespace Examples\Complexity;

/**
 * One method over the configured per-function limit, which the gate sets to 2 rather than the default of 9.
 *
 * The score is 3: the `foreach` is one increment at nesting 2 with no bonus, the `if` inside it is one plus
 * one for going deeper. A method is named `Class::method()` in the message, which is the part of this rule the
 * class-level one does not exercise.
 */
final class BadComplexFunction
{
    /** @param list<int> $numbers */
    public function total(array $numbers): int
    {
        $total = 0;
        foreach ($numbers as $number) {
            if ($number > 0) {
                $total += $number;
            }
        }

        return $total;
    }
}
