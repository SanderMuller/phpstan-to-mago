<?php

declare(strict_types=1);

namespace App\Reporting;

use Illuminate\Support\Collection;

final class BadChainedDumpInApp
{
    public function report(Collection $rows): void
    {
        // A chained debug call on a Laravel-declared method, inside `App`.
        $rows->dump();
    }
}
