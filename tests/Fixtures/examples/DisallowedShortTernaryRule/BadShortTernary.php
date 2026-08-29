<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadShortTernary
{
    /** A short ternary, which is the whole of what this rule reports. */
    public function label(string $name): string
    {
        return $name ?: 'anonymous';
    }
}
