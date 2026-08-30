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

    /** @dataProvider provideNeither */
    public function test_needs_both(int $number): void
    {
        $this->assertSame($number, $number);
    }

    /**
     * Neither static nor public, which is two findings at one line.
     *
     * The rule tests the two properties in separate `if` blocks and reports both at the provider's own line,
     * so this is the only shape where a port emitting one check and not the other still agrees on every
     * other example. It survives the differential only because the two carry different identifiers — a
     * collapse there needs the same identifier *and* the same line, which is what made
     * `TraitRequiresInterfaceRule` the live case and leaves this one safe.
     */
    private function provideNeither(): array
    {
        return [[3]];
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
