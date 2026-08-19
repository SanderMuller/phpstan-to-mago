<?php

declare(strict_types=1);

namespace Examples\Helpers;

/**
 * A different function, so the name guard bails before the codebase is asked anything.
 */
final class Behaved
{
    public function go(): void
    {
        assistant();
    }
}
