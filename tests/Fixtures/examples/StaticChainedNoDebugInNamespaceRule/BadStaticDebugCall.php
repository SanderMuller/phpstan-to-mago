<?php

declare(strict_types=1);

namespace App\Chained;

use App\Chained\Facades\Announcer;

/**
 * The standalone form of the check `CombinedStaticCallRule` merges, so the same branch and the same control.
 *
 * `Announcer` declares `dump()` itself, so the declaring class is not under `Illuminate\` and the rule falls
 * through to asking whether the called class descends from Laravel's facade base. That subclass test is the
 * capability this pair exists to pin; the prefix check alone would never reach it.
 */
final class BadStaticDebugCall
{
    public function announce(): void
    {
        Announcer::dump();
    }
}
