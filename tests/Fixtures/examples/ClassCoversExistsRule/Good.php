<?php

declare(strict_types=1);

namespace Examples\ClassCoversExists;

use PHPUnit\Framework\TestCase;

/**
 * The covered class exists, so the rule is silent.
 *
 * @covers \Examples\ClassCoversExists\Good
 */
final class Good extends TestCase
{
    public function test_something(): void
    {
        self::assertTrue(true);
    }
}
