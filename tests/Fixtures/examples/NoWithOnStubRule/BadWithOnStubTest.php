<?php

declare(strict_types=1);

namespace Examples\Tests;

use Examples\Doubles\Mock;
use PHPUnit\Framework\TestCase;

final class BadWithOnStubTest extends TestCase
{
    private Mock $gateway;

    public function test_it_charges(): void
    {
        // `with()` on a stub: the double can `expects()`, and this call never says so.
        $this->gateway->method('charge')->with(1);
    }
}
