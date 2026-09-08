<?php

declare(strict_types=1);

namespace App\Chained\Facades;

use Illuminate\Support\Facades\Facade;

/** A project facade declaring its own debug method. {@see BadStaticDebugCall} */
final class Announcer extends Facade
{
    public static function dump(): void {}
}
