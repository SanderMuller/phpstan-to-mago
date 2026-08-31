<?php declare(strict_types=1);

namespace Examples\Callables;

/** The written target the examples name. Not an example itself — neither tool reports on it. */
final class Holder
{
    public static string $prop = 'prop';

    public function run(): void {}

    public static function make(): void {}
}
