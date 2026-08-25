<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class BadEmpty
{
    /** `empty()` is forbidden outright, so the hook is the whole rule. */
    public function hasNone(array $items): bool
    {
        return empty($items);
    }

    /** Every one of them, not the first. */
    public function neither(array $items, string $name): bool
    {
        return empty($items) && empty($name);
    }
}
