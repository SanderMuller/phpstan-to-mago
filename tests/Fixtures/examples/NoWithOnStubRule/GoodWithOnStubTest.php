<?php

declare(strict_types=1);

namespace Examples\Tests;

use Examples\Doubles\Mock;
use PHPUnit\Framework\TestCase;

final class GoodWithOnStubTest extends TestCase
{
    private Mock $gateway;

    private \stdClass $plain;

    public function test_expects_first(): void
    {
        // `expects()` ahead of `method()` makes it a mock, which is what the rule asks for.
        $this->gateway->expects($this->once())->method('charge')->with(1);
    }

    public function test_with_on_something_that_cannot_expect(): void
    {
        // The receiver's type has no `expects()`, so there is no stub to misuse. This is the guard that needs
        // the *inferred type* of a sub-expression rather than its syntax — the shape is otherwise identical
        // to the bad example's.
        $this->plain->method('charge')->with(1);
    }

    public function test_with_on_a_bare_call(): void
    {
        // `with()` whose receiver is not a `method()` call at all.
        $this->gateway->expects($this->once())->with(1);
    }
}
