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
 *
 * The fourth is first-class callable syntax, and it is here as a *recorded shape rather than as a control*.
 * `getRepository(...)` hands php-parser a `VariadicPlaceholder`, so the rule's `instanceof Arg` answers
 * false, where the port reads that test as "an argument is present" and may answer true.
 *
 * **This line cannot tell those apart, and it was nearly committed as though it could.** `isDynamicArg()`
 * returns true — the rule stays silent — both when there is no `Arg` *and* when the argument's value is
 * neither a `String_` nor a `Name`-classed constant. A first-class callable takes the second path whatever
 * mago yields, so both engines are silent either way and the gate passing here says nothing about the
 * mapping. It is kept because the shape belongs in the fixture, not because it settles anything: separating
 * the two needs a rule that reports on the no-argument branch, and this one does not have one.
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

    public function asCallable(): callable
    {
        return $this->entityManager->getRepository(...);
    }
}
