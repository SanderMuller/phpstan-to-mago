<?php

declare(strict_types=1);

namespace App\Probe;

final class Widget
{
    public function __construct(public bool $enabled = false) {}

    public function toggle(bool $on): void {}
}
