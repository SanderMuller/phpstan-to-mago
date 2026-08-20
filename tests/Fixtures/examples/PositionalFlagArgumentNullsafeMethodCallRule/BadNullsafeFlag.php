<?php

declare(strict_types=1);

namespace App\Widgets;

final class BadNullsafeFlag
{
    public function toggle(?NullsafeWidget $widget): void
    {
        $widget?->setEnabled(true);
    }
}
