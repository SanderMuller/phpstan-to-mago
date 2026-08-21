<?php

declare(strict_types=1);

namespace Examples\Dynamic;

/** The written target the good example names. Not an example itself — neither tool reports on it. */
final class Holder
{
    public const string FIXED = 'fixed';

    public static string $prop = 'prop';

    public string $inst = 'inst';

    public function run(): string
    {
        return 'run';
    }

    public static function make(): self
    {
        return new self();
    }
}
