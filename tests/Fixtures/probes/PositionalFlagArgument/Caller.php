<?php

declare(strict_types=1);

namespace App\Probe;

final class Caller
{
    public function positionalConstructor(): Widget
    {
        return new Widget(true);
    }

    public function positionalNullsafe(?Widget $widget): void
    {
        $widget?->toggle(true);
    }

    public function named(?Widget $widget): void
    {
        $widget?->toggle(on: true);
    }
}
