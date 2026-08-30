<?php

declare(strict_types=1);

namespace App\Widgets;

final class GoodDisagreement
{
    /**
     * @param (DimmableOne&DimmableTwo)|null $widget
     */
    public function dim(?object $widget): void
    {
        // Two declarers name the flag parameter differently, so neither tool can say which name to suggest.
        $widget?->dim(true);
    }
}
