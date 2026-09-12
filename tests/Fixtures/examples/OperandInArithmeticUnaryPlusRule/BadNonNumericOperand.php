<?php

declare(strict_types=1);

namespace Examples\ArithmeticPlus;

/**
 * The two operand types the rule reports: both coerce to a number and neither is one.
 *
 * Measured on a real PHPStan run, one operand of every shape: `bool` and `null` report at every flag
 * setting, and nothing else reports at the flags this gate runs.
 */
final class NonNumericOperand
{
    public function flag(bool $enabled): int
    {
        return +$enabled;
    }

    public function nothing(null $nothing): int
    {
        return +$nothing;
    }
}
