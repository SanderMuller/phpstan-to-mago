<?php

declare(strict_types=1);

namespace App\Support;

use App\Chained\Ordinary;

/**
 * A class that is not a facade, declaring a method with a debug name. Its own file, for the reason
 * {@see Ordinary} records: as a second class in the caller's file it was never reached.
 */
final class Plain
{
    public static function dump(): void {}

    public static function keep(): void {}
}
