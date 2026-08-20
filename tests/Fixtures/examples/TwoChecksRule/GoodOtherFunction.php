<?php

declare(strict_types=1);

namespace Checks\Reporting;

/** Inside the namespace, but neither check's function: each check declines on its own name. */
final class GoodOtherFunction
{
    public function report(array $rows): int
    {
        return count($rows);
    }
}
