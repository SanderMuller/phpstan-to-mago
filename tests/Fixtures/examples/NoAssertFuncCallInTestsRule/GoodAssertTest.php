<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class GoodAssertTest extends TestCase
{
    public function test_it_holds(): void
    {
        $this->assertSame(1, 1);
    }

    public function assertSame(mixed $expected, mixed $actual): void {}
}
