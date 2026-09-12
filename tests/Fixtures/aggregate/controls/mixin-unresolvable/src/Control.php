<?php declare(strict_types=1);

namespace Control;

use Predis\Client;

/**
 * A `@mixin` naming a class nothing in the project resolves, which locks nothing.
 *
 * Written as the first hypothesis for `laravel/framework`'s `@mixin \Predis\Client`, where predis is not
 * installed — and refuted by this control: PHPStan counts all three declarations, so an unresolvable mixin is
 * not what makes the collector skip a method. The real cause was a *resolvable* one two files away.
 */
class Mixin
{
    public function invented(string $one, int $two): void {}
}

/** @mixin Client */
class Base {}

final class Subject extends Base
{
    public function invented(string $one, int $two): void {}

    public function plain(string $only): void {}
}
