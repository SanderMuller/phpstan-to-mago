<?php

declare(strict_types=1);

namespace Examples\Expressions;

/** One site per branch, so a lost branch is one missing finding rather than none. */
final class BadComputedNames
{
    public function run(string $name, object $subject, string $class, callable $fn): mixed
    {
        $staticProperty = $class::$$name;
        $method = $subject->$name();

        return [$staticProperty, $method, $fn];
    }
}
