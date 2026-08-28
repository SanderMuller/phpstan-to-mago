<?php

declare(strict_types=1);

namespace Examples\Operators;

final class GoodDivision
{
    public function share(int $enabled, int $total): float
    {
        return $enabled / $total;
    }
}
