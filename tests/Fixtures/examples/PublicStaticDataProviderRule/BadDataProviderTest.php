<?php

declare(strict_types=1);

namespace Examples\DataProvider;

use PHPUnit\Framework\TestCase;

final class BadDataProviderTest extends TestCase
{
    /**
     * No provider at all, and deliberately first.
     *
     * The rule reads the provider name out of a docblock through an inlined resolver, and that resolver's
     * "no name here" is a `return null`. Translated as a bail it ended the whole rule at this method, so
     * neither finding below was reported — narrower than the rule, through a plausible-looking emission. This
     * method is what makes the difference between `return` and `continue` visible.
     */
    public function test_without_any_provider(): void
    {
        $this->assertTrue(true);
    }

    /** @dataProvider provideNotStatic */
    public function test_needs_static_provider(int $number): void
    {
        $this->assertSame($number, $number);
    }

    /** @dataProvider provideNotPublic */
    public function test_needs_public_provider(int $number): void
    {
        $this->assertSame($number, $number);
    }

    public function provideNotStatic(): array
    {
        return [[1]];
    }

    private static function provideNotPublic(): array
    {
        return [[2]];
    }
}
