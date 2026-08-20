<?php

declare(strict_types=1);

namespace App\Reporting;

final class BadInvadeInApp
{
    public function readPrivateState(object $subject): mixed
    {
        // Reported under its own identifier: this one is disallowed in any namespace.
        $first = \Livewire\invade($subject)->hidden;

        // Reported under a different identifier: `invade()` at all, because this file is in `App`.
        $second = invade($subject)->hidden;

        return [$first, $second];
    }
}
