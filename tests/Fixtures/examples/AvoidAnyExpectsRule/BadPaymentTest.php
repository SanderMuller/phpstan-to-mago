<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class BadPaymentTest extends TestCase
{
    public function test_it_charges(): void
    {
        $mock = $this->createMock('Gateway');
        $mock->expects($this->any())->method('charge');
    }

    public function any(): object
    {
        return new \stdClass();
    }
}
