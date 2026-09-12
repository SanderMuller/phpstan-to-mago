<?php

declare(strict_types=1);

namespace App\Support;

use App\Facades\Reporter;

/**
 * A static debug call reached through the rule's facade branch.
 *
 * `Reporter` declares `dump()` itself, so its declaring class is `App\Facades\Reporter` and the earlier
 * check — does the declaring class start with `Illuminate\` — is false. The rule then asks whether the
 * called class descends from `Illuminate\Support\Facades\Facade`, which is the branch this example exists
 * for: without it the pair would pass on the prefix check alone and say nothing about the subclass test.
 *
 * The namespace matters too. The package wires `namespaces: [App]`, so a call outside it is not this rule's
 * business at all — `GoodStaticCalls` holds that control.
 */
final class BadStaticDebugCalls
{
    public function report(): void
    {
        Reporter::dump();
    }
}
