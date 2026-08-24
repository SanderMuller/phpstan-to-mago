<?php

declare(strict_types=1);

namespace Examples\ReflectedMocked;

use PHPUnit\Framework\TestCase;

final class GoodTest extends TestCase
{
    /** A name nothing declares, so the helper's `hasClass()` guard returns null. */
    public function test_it_mocks_an_unknown_class(): void
    {
        $this->createMock('Examples\Stubs\Mocked\Absent');
    }

    /** Not a constant string, so the helper's first guard returns null before the name is read. */
    public function test_it_mocks_a_computed_name(): void
    {
        $this->createMock($this->name());
    }

    private function name(): string
    {
        return 'Examples\Stubs\Mocked\Concrete';
    }
}
