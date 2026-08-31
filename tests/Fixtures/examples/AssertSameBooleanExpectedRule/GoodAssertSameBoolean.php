<?php declare(strict_types=1);

namespace Examples\Asserts;

use PHPUnit\Framework\TestCase;

/** Not an `Assert`, and it declares an `assertSame()` of its own. */
final class NotAnAssertEither
{
    public function assertSame(mixed $expected, mixed $actual): void {}
}

final class GoodAssertSameBoolean extends TestCase
{
    public function test_it(): void
    {
        $value = $this->value();

        // The rule's own advice, which it must not then report.
        $this->assertTrue($value);
        $this->assertFalse($value);

        // A constant name that is neither `true` nor `false`.
        $this->assertSame(null, $value);

        // An expected boolean on something that is not an `Assert`.
        $other = new NotAnAssertEither();
        $other->assertSame(true, $value);
    }

    private function value(): bool
    {
        return true;
    }
}
