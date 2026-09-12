<?php

declare(strict_types=1);

namespace Examples\DimFetch;

final class AssignsIntoNothing
{
    public function neverCreated(): array
    {
        $rows['first'] = 'value';

        return $rows;
    }
}
