<?php

declare(strict_types=1);

namespace Examples\Services;

use Doctrine\ORM\EntityManager;

/**
 * Three ways to be silent, and two of them measure one fold each.
 *
 * The first is the rule's own exemption: a class whose name ends in `Repository` may look one up. The second
 * and third are `isDynamicArg()`, the helper whose branch binds the narrowed node before answering — a plain
 * variable argument, and a class constant whose class is an expression rather than a written name. Both are
 * dynamic, so the rule declines to guess, and a fold that read the binding as a step would report here.
 */
final class OrderRepository
{
    public function __construct(private EntityManager $entityManager) {}

    public function load(): object
    {
        return $this->entityManager->getRepository(OrderRepository::class);
    }
}

final class DynamicLookups
{
    public function __construct(private EntityManager $entityManager) {}

    public function byName(string $name): object
    {
        return $this->entityManager->getRepository($name);
    }

    public function byObject(object $subject): object
    {
        return $this->entityManager->getRepository($subject::class);
    }
}
