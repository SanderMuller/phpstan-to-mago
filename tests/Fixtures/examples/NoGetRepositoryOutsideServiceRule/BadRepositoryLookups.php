<?php

declare(strict_types=1);

namespace Examples\Services;

use Doctrine\ORM\EntityManager;

/**
 * A repository fetched from the entity manager in a class that is not one, which is what the rule forbids.
 *
 * The class name does not end in `Repository`, so the trailing report is the one that fires — the report the
 * emitted plugin used to drop, because the rule also reports early for a call outside any class.
 */
final class OrderService
{
    public function __construct(private EntityManager $entityManager) {}

    public function load(): object
    {
        return $this->entityManager->getRepository(OrderService::class);
    }
}
