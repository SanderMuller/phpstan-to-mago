<?php

declare(strict_types=1);

namespace Examples\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;

final class OrderFixture implements FixtureInterface
{
    public function load(object $manager): void
    {
        $manager->persist(new \stdClass());
    }
}
