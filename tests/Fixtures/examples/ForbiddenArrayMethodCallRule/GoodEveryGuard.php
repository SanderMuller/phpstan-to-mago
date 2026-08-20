<?php

declare(strict_types=1);

namespace Examples\ArrayCallable;

final class GoodEveryGuard
{
    public function handle(): void {}

    /** Three elements, so it is not a callable pair. */
    public function tooMany(): array
    {
        return [$this, 'handle', 'extra'];
    }

    /**
     * The named method does not exist, so there is no array method call to forbid.
     *
     * This is the guard that needs the *codebase*, not the syntax: both elements are shaped exactly like the
     * bad example's.
     */
    public function noSuchMethod(): array
    {
        return [$this, 'missing'];
    }

    /**
     * The first element is not an object.
     *
     * `soleObjectClass()` of a `string` type is null, and this is what makes that check testable — the shape
     * is identical to the bad example's otherwise.
     */
    public function notAnObject(): array
    {
        return ['handle', 'handle'];
    }

    /** The second element is not a constant string, so no method name can be read from it. */
    public function computedName(string $method): array
    {
        return [$this, $method];
    }
}
