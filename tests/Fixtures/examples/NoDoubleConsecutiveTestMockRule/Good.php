<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class RefundTest extends TestCase
{
    public function test_it_refunds(): void
    {
        $mock = $this->createMock('Refund');
        $mock->method('credit')->willReturnOnConsecutiveCalls(1, 2);
    }
}
