<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class GoodTernary
{
    public function describe(string $name): string
    {
        return $name !== '' ? $name : 'anonymous';
    }

    /**
     * An elvis with a non-boolean condition, which the rule skips.
     *
     * This is the control for the middle-arm test. `$node->if === null` is the rule's first guard, and the
     * port answers it by counting a `Conditional`'s arms -- three for a ternary, two for an elvis. Reading
     * arm 1 by position instead would find the *else* arm here, never null, and this line would report where
     * PHPStan is silent.
     */
    public function fallback(string $name): string
    {
        return $name ?: 'anonymous';
    }
}
