<?php

declare(strict_types=1);

namespace Examples\Arguments;

final class Enough
{
    public function call(): string
    {
        return needsTwo('one', 'two');
    }

    /**
     * A different function with one argument, so the name test is load-bearing and not just the count.
     */
    public function other(): string
    {
        return needsOne('one');
    }
}

function needsOne(string $value): string
{
    return $value;
}
