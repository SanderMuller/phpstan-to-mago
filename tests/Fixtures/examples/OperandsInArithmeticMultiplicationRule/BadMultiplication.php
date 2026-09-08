<?php

declare(strict_types=1);

namespace Examples\Operators;

/**
 * A boolean operand on each side of `*`, in both spellings.
 *
 * `bool` is the operand to use: measured at this gate's configuration, `array`, `object` and plain `string`
 * are silent in every setting because PHPStan core reports those and `OperatorRuleHelper` returns early
 * rather than repeat it. The compound row is the dispatch's other arm — php-parser splits `*` and `*=`
 * into `BinaryOp` and `AssignOp` classes and the rule handles both in one `if`/`elseif`.
 */
final class BadMultiplication
{
    public function left(bool $enabled, int $total): mixed
    {
        return $enabled * $total;
    }

    public function compound(bool $enabled): mixed
    {
        $enabled *= 2;

        return $enabled;
    }

    public function right(int $total, bool $enabled): mixed
    {
        return $total * $enabled;
    }
}
