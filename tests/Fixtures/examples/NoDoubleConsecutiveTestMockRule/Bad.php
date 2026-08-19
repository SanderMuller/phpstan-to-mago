<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function test_it_pays(): void
    {
        $mock = $this->createMock('Payment');
        $mock->method('charge')->willReturnOnConsecutiveCalls(1, 2)->willReturnCallback(static fn (): int => 3);
    }
}
