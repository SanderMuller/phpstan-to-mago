<?php

declare(strict_types=1);

namespace Examples\PostDecrement;

/**
 * A numeric string, which the original allows because `isSubtypeOfNumber()` accepts one.
 *
 * A *plain* string is deliberately not here. PHPStan reports `$text--` on one and says nothing about
 * this, and mago erases the difference — both arrive as the same atomic — so the port passes both. That is
 * the one divergence in this family, stated in `RuleLevel::isValidForIncrementOrDecrement()`, and a pair
 * asserting agreement cannot hold a case where the two disagree.
 */
final class NumericStringOperand
{
    /** @param numeric-string $numeric */
    public function strings(string $numeric): void
    {
        $numeric--;
    }
}
