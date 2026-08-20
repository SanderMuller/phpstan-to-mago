<?php

declare(strict_types=1);

namespace App\Reporting;

/**
 * One file per check the merged rule asks, because one report proves only one check runs.
 *
 * The rule merges three sub-rules into a single dispatch, and flattened into one guard chain the first
 * check's "not my case" silenced the other two. Four findings here is what says all three run.
 */
final class BadCombinedFuncCalls
{
    public function everyCheck(object $subject, mixed $fallback): array
    {
        // The debug check: a debug call inside the App namespace.
        dump($subject);

        // The invade check, under its own identifier: disallowed in any namespace.
        $viaLivewire = \Livewire\invade($subject)->hidden;

        // The invade check again, under the other identifier: `invade()` at all, because this is App.
        $direct = invade($subject)->hidden;

        // The request check: unvalidated request data through the global helper.
        $name = request('name');

        return [$viaLivewire, $direct, $name, $fallback];
    }
}
