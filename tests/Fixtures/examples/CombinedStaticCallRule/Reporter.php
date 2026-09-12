<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/** A project facade declaring its own debug method, so the declaring class is not under `Illuminate\`. */
final class Reporter extends Facade
{
    public static function dump(): void {}
}
