<?php

declare(strict_types=1);

namespace Fixtures\Examples\MockMethodCallRule;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

interface Gateway
{
    public function send(string $payload): bool;
}

final class BadMockMethodCall extends TestCase
{
    public function test_mocking_a_method_the_class_does_not_have(): void
    {
        /** @var Gateway&MockObject $gateway */
        $gateway = $this->createMock(Gateway::class);

        // The class has no `receive()`, so mocking it is the finding.
        $gateway->method('receive');
    }

    public function test_the_same_through_an_expects_chain(): void
    {
        /** @var Gateway&MockObject $gateway */
        $gateway = $this->createMock(Gateway::class);

        // The rule asks its check twice for this node: once about what `expects()` returns and once about
        // the mock behind it. Its `continue` means at most one finding per method name, which is what the
        // emitted plugin has to reproduce.
        $gateway->expects(self::once())->method('receive');
    }
}
