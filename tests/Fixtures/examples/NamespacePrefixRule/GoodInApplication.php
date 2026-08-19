<?php

declare(strict_types=1);

namespace Application\Services;

/**
 * `Application` starts with `App` as a string but is a different namespace. The prefix carries its
 * trailing separator so this must not match.
 */
final class Reporter
{
    public function go(): void
    {
        dump('x');
    }
}
