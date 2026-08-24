<?php

declare(strict_types=1);

namespace Examples\DocumentMocking;

use Examples\Stubs\Domain\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class BadTest extends TestCase
{
    /** A concrete class under `\Entity\`, which is what the rule forbids mocking. */
    public function test_it_mocks_an_entity(): void
    {
        $this->createMock(Invoice::class);
    }
}
