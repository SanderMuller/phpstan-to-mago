<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class GoodWhile
{
    public function drain(string $queue): int
    {
        $seen = 0;
        while ($queue !== '') {
            $seen++;
            $queue = substr($queue, 1);
        }

        return $seen;
    }
}
