<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadElseIf
{
    public function label(bool $primary, string $fallback): string
    {
        if ($primary) {
            return 'primary';
        } elseif ($fallback) {
            return $fallback;
        }

        return 'none';
    }
}
