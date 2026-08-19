<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class BadAssertTest extends TestCase
{
    public function test_it_holds(): void
    {
        assert(1 === 1);
    }
}
