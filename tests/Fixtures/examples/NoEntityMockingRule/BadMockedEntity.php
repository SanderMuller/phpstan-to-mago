<?php

declare(strict_types=1);

namespace Examples\Doctrine;

use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\TestCase;

/** An entity mapped by attribute, which is the half of the check the port can ask. */
#[Entity]
final class Product
{
    public int $id = 0;
}

/** Mocking it is what the rule reports. */
final class ProductTest extends TestCase
{
    public function test_it_mocks(): void
    {
        $this->createMock(Product::class);
    }
}
