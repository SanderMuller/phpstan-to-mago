<?php

declare(strict_types=1);

namespace Fixtures\Examples\MockMethodCallRule;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class Plain
{
    public function method(string $name): void {}
}

final class GoodMockMethodCall extends TestCase
{
    public function test_a_method_the_mocked_class_really_has(): void
    {
        /** @var Gateway&MockObject $gateway */
        $gateway = $this->createMock(Gateway::class);

        $gateway->method('send');
    }

    public function test_a_receiver_that_is_not_a_mock_at_all(): void
    {
        // The control for the defect this rule's port had at one point: with the report emitted outside the
        // branch that tests for `MockObject`, a plain receiver reached it and every `method()` call became a
        // finding. Nothing here is a mock, so both tools stay silent.
        $plain = new Plain();

        $plain->method('receive');
    }
}
