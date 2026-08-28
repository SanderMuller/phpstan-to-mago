<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class GoodNot
{
    public function missing(string $name): bool
    {
        return $name === '';
    }
}
