<?php

declare(strict_types=1);

namespace Examples\Forwarding;

/**
 * A different function, so the helper the shim forwards to answers null.
 */
final class Behaved
{
    public function go(): void
    {
        permitted();
    }
}
