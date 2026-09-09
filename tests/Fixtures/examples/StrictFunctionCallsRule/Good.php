<?php

declare(strict_types=1);

namespace Examples\StrictFunctionCalls;

final class Good
{
    /** @param list<string> $haystack */
    public function strictIsTrue(string $needle, array $haystack): bool
    {
        return in_array($needle, $haystack, true);
    }

    /**
     * Named arguments, which PHPStan reorders and this port declines. Both engines are silent here because
     * the strictness argument is `true` either way -- the shape Laravel's `Rules\Enum` writes, and the only
     * named-argument call to these functions in five vendored trees.
     *
     * @param list<string> $haystack
     */
    public function named(string $needle, array $haystack): bool
    {
        return in_array(needle: $needle, haystack: $haystack, strict: true);
    }

    /** A function the rule's table does not name. */
    public function untabled(string $needle, string $haystack): bool
    {
        return str_contains($haystack, $needle);
    }
}
