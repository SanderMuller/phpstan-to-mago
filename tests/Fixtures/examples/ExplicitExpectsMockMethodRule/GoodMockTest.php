<?php

declare(strict_types=1);

namespace Examples\Tests;

use Examples\Doubles\Mock;
use PHPUnit\Framework\TestCase;

final class GoodMockTest extends TestCase
{
    private Mock $gateway;

    public function test_it_charges(): void
    {
        $this->gateway->expects($this->once())->method('charge');
    }

    public function once(): object
    {
        return new \stdClass();
    }
}
