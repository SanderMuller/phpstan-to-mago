<?php

declare(strict_types=1);

namespace Examples\Repository;

use Doctrine\ORM\EntityManager;

final class OrderRepository
{
    public function __construct(private readonly EntityManager $entityManager) {}
}
