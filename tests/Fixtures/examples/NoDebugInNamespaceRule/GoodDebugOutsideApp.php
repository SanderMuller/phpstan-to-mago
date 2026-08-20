<?php

declare(strict_types=1);

namespace Examples\Outside;

final class GoodDebugOutsideApp
{
    public function report(array $rows): void
    {
        // The rule reports in `App` and `Tests` only, so a debug call here is none of its business.
        dump($rows);
    }

    public function notADebugFunction(array $rows): int
    {
        return count($rows);
    }
}
