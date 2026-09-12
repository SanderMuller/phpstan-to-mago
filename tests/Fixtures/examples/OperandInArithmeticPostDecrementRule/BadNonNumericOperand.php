<?php

declare(strict_types=1);

namespace Examples\PostDecrement;

use stdClass;

/**
 * The four operand shapes this family reports at every flag setting.
 *
 * Wider than the arithmetic rules on purpose: `isValidForIncrement()` and `isValidForDecrement()` have no
 * `toNumber()` pass, so an array and an object are this rule's own findings rather than PHPStan core's.
 */
final class NonNumericOperand
{
    public function flag(bool $enabled): void
    {
        $enabled--;
    }

    public function nothing(null $nothing): void
    {
        $nothing--;
    }

    public function list(array $list): void
    {
        $list--;
    }

    public function money(stdClass $money): void
    {
        $money--;
    }
}
