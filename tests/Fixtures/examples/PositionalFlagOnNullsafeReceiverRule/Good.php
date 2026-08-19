<?php

declare(strict_types=1);

namespace Examples\Nullsafe;

use Vendor\Widget as VendorWidget;

final class Behaved
{
    private ?Widget $maybe = null;

    private ?VendorWidget $vendor = null;

    public function go(array $rest): void
    {
        // Named, which is what the rule asks for.
        $this->maybe?->toggle(enabled: true);
        // Spread, so the argument position says nothing about the parameter position.
        $this->maybe?->toggle(...$rest);
        // Not a bare bool or null.
        $this->maybe?->toggle('true');
        // The method is declared outside the first-party namespaces.
        $this->vendor?->toggle(true);
    }
}
