<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Two controls for the facade branch, both silent.
 *
 * `Plain` is not a facade, so the subclass test is what declines it — and it declares a method with a debug
 * name, so nothing but that test separates it from `BadStaticDebugCalls`. A port answering the subclass
 * question wrongly reports this one.
 *
 * `Plain::keep()` is a static call the rule looks at and whose name is not a debug statement, which is the
 * name guard rather than the subclass guard.
 */
final class GoodStaticCalls
{
    public function report(): void
    {
        Plain::dump();
        Plain::keep();
    }
}
