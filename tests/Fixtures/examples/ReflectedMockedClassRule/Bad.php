<?php

declare(strict_types=1);

namespace Examples\ReflectedMocked;

use Examples\Stubs\Mocked\Concrete;
use PHPUnit\Framework\TestCase;

final class BadTest extends TestCase
{
    /** A class the codebase knows, so the helper hands back a reflection and the guard passes. */
    public function test_it_mocks_a_known_class(): void
    {
        $this->createMock(Concrete::class);
    }
}
