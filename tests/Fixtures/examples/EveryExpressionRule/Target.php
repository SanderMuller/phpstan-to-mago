<?php

declare(strict_types=1);

namespace Examples\Expressions;

/** The written target the good example names. */
final class Target
{
    public static string $prop = 'prop';

    public function run(): string
    {
        return 'run';
    }
}
