<?php

declare(strict_types=1);

namespace Examples\Mapping;

final class Doubler
{
    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    public function double(array $values): array
    {
        return array_map(static fn (int $value): int => $value * 2, $values);
    }

    /**
     * `array_map(...)` is a first-class callable, which the original rule bails on through
     * `isFirstClassCallable()`. The transpiler drops that guard, claiming Mago parses this as a partial
     * application that never reaches a call hook. If that claim is wrong, the port reports here.
     */
    public function mapper(): callable
    {
        return array_map(...);
    }
}
