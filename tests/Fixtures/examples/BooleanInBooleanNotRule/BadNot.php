<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadNot
{
    public function missing(string $name): bool
    {
        return ! $name;
    }
}
