<?php

declare(strict_types=1);

namespace Examples\ProjectConfigured;

final class GoodAllowedCalls
{
    public function notConfigured(): void
    {
        // Neither list names it, and the project is the only place either list comes from.
        printf('x');
    }
}
