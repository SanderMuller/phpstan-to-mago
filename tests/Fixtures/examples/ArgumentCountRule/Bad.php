<?php

declare(strict_types=1);

namespace Examples\Arguments;

function needsTwo(string ...$values): string
{
    return implode(',', $values);
}

final class TooFew
{
    public function call(): string
    {
        // One argument where the rule wants at least two, so the count comparison is what decides.
        return needsTwo('one');
    }
}
