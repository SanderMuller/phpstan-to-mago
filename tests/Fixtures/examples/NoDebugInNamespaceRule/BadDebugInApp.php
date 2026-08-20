<?php

declare(strict_types=1);

namespace App\Reporting;

final class BadDebugInApp
{
    public function report(array $rows): void
    {
        dump($rows);
    }
}
