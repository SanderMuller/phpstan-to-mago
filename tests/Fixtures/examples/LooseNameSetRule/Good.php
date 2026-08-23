<?php

declare(strict_types=1);

namespace Examples\LooseNames;

final class Allowed
{
    public function permitted(): void {}

    /**
     * A name the haystack does not hold. Under a loose comparison a numeric-string haystack entry would
     * widen what matches; there is none here, which is the condition the transpiler checks.
     */
    public function call(self $other): void
    {
        $other->permitted();
    }
}
