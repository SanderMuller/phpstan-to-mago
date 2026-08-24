<?php

declare(strict_types=1);

namespace Examples\MockedClassKind;

use Examples\Stubs\Mocked\Contract;
use Examples\Stubs\Mocked\Pending;
use PHPUnit\Framework\TestCase;

final class GoodTest extends TestCase
{
    /** Abstract, so the first predicate skips it. */
    public function test_it_mocks_an_abstract_class(): void
    {
        $this->createMock(Pending::class);
    }

    /** An interface, so the second one does. */
    public function test_it_mocks_an_interface(): void
    {
        $this->createMock(Contract::class);
    }

    /** A name nothing declares, so `hasClass()` skips it before either predicate is asked. */
    public function test_it_mocks_an_unknown_class(): void
    {
        $this->createMock('Examples\Stubs\Mocked\Missing');
    }
}
