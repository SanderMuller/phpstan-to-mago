<?php

declare(strict_types=1);

namespace App\Chained;

/**
 * The control: a static call with a debug name on a class that is not a facade.
 *
 * Nothing but the subclass test separates this from `BadStaticDebugCall`, so a port answering that question
 * wrongly reports it.
 */
final class GoodStaticCall
{
    public function announce(): void
    {
        Ordinary::dump();
        Ordinary::keep();
    }
}
