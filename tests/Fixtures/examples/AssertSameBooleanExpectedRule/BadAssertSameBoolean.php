<?php declare(strict_types=1);

namespace Examples\Asserts;

use PHPUnit\Framework\TestCase;

/**
 * Both branches, each reporting its own message under its own identifier. One is enough to emit the rule and
 * not enough to prove it: a port that took the last identifier for both would still pass a one-branch pair.
 */
final class BadAssertSameBoolean extends TestCase
{
    public function test_it(): void
    {
        $value = $this->value();

        $this->assertSame(true, $value);
        self::assertSame(false, $value);

        // Written in the other case, which the rule folds.
        $this->assertSame(TRUE, $value);
    }

    private function value(): bool
    {
        return true;
    }
}
