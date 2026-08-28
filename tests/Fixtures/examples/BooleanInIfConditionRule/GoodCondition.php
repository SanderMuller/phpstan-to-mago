<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class GoodCondition
{
    public function describe(string $name): string
    {
        if ($name !== '') {
            return $name;
        }

        return 'anonymous';
    }
}
