<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class GoodEmpty
{
    /** The stricter comparison the rule asks for. */
    public function hasNone(array $items): bool
    {
        return $items === [];
    }

    /** `isset()` is a different construct and a different node kind. */
    public function isMissing(array $items): bool
    {
        return ! isset($items['key']);
    }
}
