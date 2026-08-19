<?php

declare(strict_types=1);

namespace Examples\Nullsafe;

final class Widget
{
    public function toggle(bool $enabled): void {}
}

final class Caller
{
    private ?Widget $maybe = null;

    public function go(): void
    {
        $this->maybe?->toggle(true);
    }
}
