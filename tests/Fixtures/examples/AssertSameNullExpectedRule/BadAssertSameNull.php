<?php declare(strict_types=1);

namespace Examples\Asserts;

use PHPUnit\Framework\TestCase;

final class BadAssertSameNull extends TestCase
{
    public function test_it(): void
    {
        $value = $this->value();

        $this->assertSame(null, $value);
        self::assertSame(null, $value);
        // Written in the other case, which the rule folds. Reading the constant name as written left the port
        // silent here while PHPStan reported.
        $this->assertSame(NULL, $value);
    }

    private function value(): ?string
    {
        return null;
    }
}
