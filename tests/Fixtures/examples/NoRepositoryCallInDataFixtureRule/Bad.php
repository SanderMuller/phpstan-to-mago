<?php

declare(strict_types=1);

namespace Examples\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManager;

final class ProductFixture implements FixtureInterface
{
    public function __construct(private readonly EntityManager $entityManager) {}

    public function load(object $manager): void
    {
        $this->entityManager->getRepository('Product');
    }
}
