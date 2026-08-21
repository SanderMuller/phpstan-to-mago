<?php

declare(strict_types=1);

namespace App\Reporting;

/**
 * An alias the *consumer's* config declares, used bare outside Blade.
 *
 * Laravel's own 46 cannot cover this one, so a port reading only `Facade::defaultAliases()` finds nothing
 * here and says the file is clean. That is the silent half of the gap, which is why it has its own example.
 */
final class BadConsumerAlias
{
    public function readThroughConsumerAlias(): void
    {
        \Reporting::summarise();
    }
}
