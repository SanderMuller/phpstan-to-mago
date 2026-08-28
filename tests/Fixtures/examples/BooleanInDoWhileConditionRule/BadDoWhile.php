<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class BadDoWhile
{
    public function drain(string $queue): int
    {
        $seen = 0;
        do {
            $seen++;
            $queue = substr($queue, 1);
        } while ($queue);

        return $seen;
    }
}
