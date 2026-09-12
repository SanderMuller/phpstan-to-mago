<?php

declare(strict_types=1);

namespace Examples\Doctrine;

/**
 * A `createQueryBuilder()` on an object that is none of the three Doctrine classes the rule accepts.
 *
 * `EntityManagerLike` is a plain class of this project, so the receiver is a single object type that is not
 * an `EntityRepository`, a `DocumentRepository` or a `Connection` — the one case the rule reports.
 */
final class EntityManagerLike
{
    public function createQueryBuilder(): object
    {
        return new \stdClass();
    }
}

final class BuildsQueries
{
    public function build(EntityManagerLike $entityManager): object
    {
        return $entityManager->createQueryBuilder();
    }
}
