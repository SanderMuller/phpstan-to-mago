<?php

declare(strict_types=1);

namespace Examples\DocumentMocking;

use Examples\Stubs\Domain\Entity\Ledger;
use Examples\Stubs\Domain\Entity\Receipt;
use Examples\Stubs\Domain\Plain\Note;
use PHPUnit\Framework\TestCase;

final class GoodTest extends TestCase
{
    /** Abstract, so the inlined helper's first inner guard skips it. */
    public function test_it_mocks_an_abstract_entity(): void
    {
        $this->createMock(Ledger::class);
    }

    /** An interface, so the second one does. */
    public function test_it_mocks_an_entity_interface(): void
    {
        $this->createMock(Receipt::class);
    }

    /** A name holding neither marker, so the guard before the helper stops it. */
    public function test_it_mocks_a_plain_class(): void
    {
        $this->createMock(Note::class);
    }
}
