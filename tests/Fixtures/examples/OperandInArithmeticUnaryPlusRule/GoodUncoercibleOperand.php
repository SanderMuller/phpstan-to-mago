<?php

declare(strict_types=1);

namespace Examples\ArithmeticPlus;

use stdClass;

/**
 * Operands PHPStan core already reports on, so this rule stays quiet: its own comment says "already
 * reported by PHPStan core", and every one of these fails `toNumber()`.
 */
final class UncoercibleOperand
{
    /** @param numeric-string $numeric */
    public function others(string $text, string $numeric, array $list, stdClass $object, object $bare, mixed $anything): void
    {
        echo +$text;
        echo +$numeric;
        echo +$list;
        echo +$object;
        echo +$bare;
        echo +$anything;
    }
}
