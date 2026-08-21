<?php

declare(strict_types=1);

namespace Examples\Complexity;

/**
 * A class over the configured limit, which the gate sets to 3 rather than the package default of 40.
 *
 * The score is the sum over the class's own methods, and the arithmetic here is the model's: `foreach` at
 * nesting 2 is 1 with no bonus, the `if` inside it is 1 plus 1 for going deeper, and the second method's `if`
 * is 1 more. Four against a limit of three.
 */
final class BadComplexClass
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

    public function label(int $number): string
    {
        if ($number > 0) {
            return 'positive';
        }

        return 'other';
    }
}
