<?php

declare(strict_types=1);

namespace Examples\Doctrine;

use PHPUnit\Framework\TestCase;

/** No mapping attribute, so mocking it is exactly what the rule allows. */
final class ProductPricer
{
    public function price(): int
    {
        return 1;
    }
}

/** And a name the codebase does not know at all, which the rule skips before it asks anything else. */
final class ProductPricerTest extends TestCase
{
    public function test_it_mocks_a_service(): void
    {
        $this->createMock(ProductPricer::class);
    }

    public function test_it_mocks_something_unknown(): void
    {
        $this->createMock('Examples\Doctrine\NoSuchClass');
    }
}
