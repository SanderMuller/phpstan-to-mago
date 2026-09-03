<?php

declare(strict_types=1);

namespace Examples\ArrayCallable;

abstract class InheritsHandling
{
    public function inherited(): void {}
}

final class BadArrayCallable extends InheritsHandling
{
    public function handle(): void {}

    /** `[$this, 'handle']` names a method that exists, which is what the rule forbids. */
    public function callable(): array
    {
        return [$this, 'handle'];
    }

    /**
     * The same callable with `__FUNCTION__` in place of the literal.
     *
     * PHPStan resolves a magic constant to its value, so the rule reads `'named'` and reports. Mago's
     * inferred type does not fold one, so the port read nothing and stayed silent — both of
     * `ForbiddenArrayMethodCallRule`'s findings on `laravel/framework` were this, and both are
     * `array_map([$this, __FUNCTION__], $value)` inside a `quoteString()`.
     */
    public function named(): array
    {
        return [$this, __FUNCTION__];
    }

    /**
     * The same callable written the long way.
     *
     * `[..]` and `array(..)` are one node to php-parser and two kinds to Mago, and the plugin registered only
     * the first — so a vendored `ClassLoader` writing `array($this, 'loadClass')` twice was missed on
     * Shopware, in both places, silently.
     *
     * The file is in pint's `notPath`, because `array_syntax` rewrites this line to `[..]` and the suite
     * stays green afterwards: the case simply stops being exercised. It did, once.
     */
    public function legacyCallable(): array
    {
        return array($this, 'handle');
    }

    /**
     * The same shape, naming a method this class *inherits* rather than declares.
     *
     * The example had no inherited method in it, and that absence let a real defect through: the port asked
     * the codebase for methods a class declares, which answers no for everything it gets from a parent. So the
     * rule went silent on `[$rectorConfig, 'make']` in `rector/rector`, where `make()` comes from the container
     * `RectorConfig` extends — and both sides of this pair agreed the whole time, because both elements of
     * `[$this, 'handle']` are written on the class itself.
     */
    public function inheritedCallable(): array
    {
        return [$this, 'inherited'];
    }
}
