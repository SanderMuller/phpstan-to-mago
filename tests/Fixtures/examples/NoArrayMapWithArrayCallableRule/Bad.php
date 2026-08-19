<?php

declare(strict_types=1);

namespace Examples\Mapping;

final class Mapper
{
    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    public function double(array $values): array
    {
        return array_map([$this, 'twice'], $values);
    }

    public function twice(int $value): int
    {
        return $value * 2;
    }
}
