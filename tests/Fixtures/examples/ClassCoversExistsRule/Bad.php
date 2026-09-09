<?php

declare(strict_types=1);

namespace Examples\ClassCoversExists;

use PHPUnit\Framework\TestCase;

/**
 * Names a class nothing declares, which is the finding.
 *
 * @covers \Examples\ClassCoversExists\NothingDeclaresThis
 */
final class Bad extends TestCase
{
    public function test_something(): void
    {
        self::assertTrue(true);
    }
}
