<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GoodMockOnlyTest extends TestCase
{
    private MockObject $gateway;

    private \stdClass $subject;

    public function test_it_charges(): void {}
}
