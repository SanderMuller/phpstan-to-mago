<?php

declare(strict_types=1);

namespace Examples\Tests;

use Examples\Doubles\Mock;
use PHPUnit\Framework\TestCase;

final class BadMockTest extends TestCase
{
    private Mock $gateway;

    public function test_it_charges(): void
    {
        $this->gateway->method('charge');
    }
}
