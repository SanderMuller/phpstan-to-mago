<?php

declare(strict_types=1);

namespace Examples\StrictFunctionCalls;

final class Bad
{
    /** @param list<string> $haystack */
    public function missingStrict(string $needle, array $haystack): bool
    {
        return in_array($needle, $haystack);
    }

    /** @param list<string> $haystack */
    public function strictIsFalse(string $needle, array $haystack): bool
    {
        return in_array($needle, $haystack, false);
    }
}
