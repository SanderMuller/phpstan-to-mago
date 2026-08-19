<?php

declare(strict_types=1);

namespace Examples\Debugging;

/**
 * `dumped()` is not in the set, and a name that merely starts with one of its members must not match.
 */
final class Clean
{
    public function go(): void
    {
        $this->dumped('x');
    }

    public function dumped(string $value): void {}
}
