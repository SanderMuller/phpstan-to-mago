<?php

declare(strict_types=1);

namespace Examples\MockedClassKind;

use Examples\Stubs\Mocked\Concrete;
use PHPUnit\Framework\TestCase;

final class BadTest extends TestCase
{
    public function test_it_mocks_a_concrete_class(): void
    {
        $this->createMock(Concrete::class);
    }
}
