<?php

declare(strict_types=1);

namespace Examples\DimFetch;

final class CreatesFirst
{
    public function createdFirst(): array
    {
        $rows = [];
        $rows['first'] = 'value';

        return $rows;
    }
}
