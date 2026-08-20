<?php

declare(strict_types=1);

namespace Examples\DataProvider;

use PHPUnit\Framework\TestCase;

final class GoodDataProviderTest extends TestCase
{
    /** No provider, so there is nothing to require of one. */
    public function test_without_any_provider(): void
    {
        $this->assertTrue(true);
    }

    /** @dataProvider provideCases */
    public function test_with_a_proper_provider(int $number): void
    {
        $this->assertSame($number, $number);
    }

    /** @dataProvider provideMissing */
    public function test_names_a_provider_that_does_not_exist(int $number): void
    {
        $this->assertSame($number, $number);
    }

    public static function provideCases(): array
    {
        return [[1]];
    }
}
