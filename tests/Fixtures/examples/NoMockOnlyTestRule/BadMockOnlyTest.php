<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BadMockOnlyTest extends TestCase
{
    private MockObject $gateway;

    private MockObject $logger;

    public function test_it_charges(): void {}
}
