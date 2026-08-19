<?php

declare(strict_types=1);

namespace Examples\Lookup;

/**
 * `ddd` is not in the constructed table, though it starts with a member of it.
 */
final class Behaved
{
    public function go(): void
    {
        ddd('x');
    }
}
