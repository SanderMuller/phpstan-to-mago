<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class GoodPaymentTest extends TestCase
{
    public function test_it_charges(): void
    {
        $mock = $this->createMock('Gateway');
        $mock->expects($this->once())->method('charge');
    }

    public function once(): object
    {
        return new \stdClass();
    }
}
