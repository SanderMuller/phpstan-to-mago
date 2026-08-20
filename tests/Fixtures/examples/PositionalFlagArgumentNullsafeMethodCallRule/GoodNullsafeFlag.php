<?php

declare(strict_types=1);

namespace App\Widgets;

use Vendor\Widget;

final class GoodNullsafeFlag
{
    public function named(?NullsafeWidget $widget): void
    {
        $widget?->setEnabled(enabled: true);
    }

    public function notABool(?NullsafeWidget $widget): void
    {
        $widget?->rename('label');
    }

    public function notNullsafe(NullsafeWidget $widget): void
    {
        // A plain method call is a different node kind, and a different rule of the same family.
        $widget->setEnabled(true);
    }

    public function thirdParty(?Widget $widget): void
    {
        // Declared outside the first-party namespaces, so the rule does not ask about it.
        $widget?->toggle(true);
    }
}
