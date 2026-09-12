<?php

declare(strict_types=1);

namespace App\Chained;

/**
 * A class that is not a facade, declaring a method with a debug name.
 *
 * Its own file, and that is load-bearing: as a second class in the caller's file the rule never reached the
 * subclass test on it, so making the test answer true unconditionally still passed the gate. The control was
 * not varying the axis it was written for.
 */
final class Ordinary
{
    public static function dump(): void {}

    public static function keep(): void {}
}
