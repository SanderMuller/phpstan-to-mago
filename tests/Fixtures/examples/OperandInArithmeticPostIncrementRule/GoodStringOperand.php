<?php

declare(strict_types=1);

namespace Examples\PostIncrement;

/**
 * A string, which the original allows: its own comment is `$a = 'a'; $a++;`.
 *
 * The increment half of the port is exact here. The decrement rules have no such pair file, because
 * PHPStan reports a plain string there and mago cannot tell it from a numeric one — the divergence
 * `RuleLevel::isValidForIncrementOrDecrement()` states.
 */
final class StringOperand
{
    /** @param numeric-string $numeric */
    public function strings(string $text, string $numeric): void
    {
        $text++;
        $numeric++;
    }
}
