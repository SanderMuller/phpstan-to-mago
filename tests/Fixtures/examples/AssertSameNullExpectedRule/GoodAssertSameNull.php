<?php declare(strict_types=1);

namespace Examples\Asserts;

use PHPUnit\Framework\TestCase;

/**
 * Not an `Assert`, and it declares an `assertSame()` of its own. The rule asks whether the *receiver* is a
 * `PHPUnit\Framework\Assert`, so this call is not its business — a port that skips that question reports it
 * and PHPStan does not.
 */
final class NotAnAssert
{
    public function assertSame(mixed $expected, mixed $actual): void {}
}

final class GoodAssertSameNull extends TestCase
{
    public function test_it(): void
    {
        $value = $this->value();

        // The rule's own advice, which it must not then report.
        $this->assertNull($value);

        // Two arguments, but not `null` as the expected one.
        $this->assertSame('', $value);

        // An `assertSame` on something that is not an `Assert`.
        $other = new NotAnAssert();
        $other->assertSame(null, $value);
    }

    private function value(): ?string
    {
        return null;
    }
}
