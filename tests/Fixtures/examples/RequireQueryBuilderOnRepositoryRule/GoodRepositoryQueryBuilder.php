<?php

declare(strict_types=1);

namespace Examples\Doctrine;

use Doctrine\ORM\EntityRepository;

/**
 * The three ways the rule stays silent, and one of them is a quirk rather than a rule.
 *
 * - `onRepository()` calls it on a real `Doctrine\ORM\EntityRepository`, which is the allow-list's own row.
 *   Without it, dropping the allow-list entirely left this pair green — measured, and the reason the stub
 *   exists.
 * - `onUnion()` is the quirk. The helper walks a union looking for a valid member and, finding none, falls
 *   through to `if (! $type instanceof ObjectType) { return true; }`, which a `UnionType` also satisfies. So a
 *   union of two classes the rule would each reject is still silent, and reducing the port to "any member is
 *   valid" reports here — narrower than the original.
 * - `onMixed()` calls it on a receiver that is not an object at all. The original asks `instanceof
 *   ObjectType` and answers true for everything else, so this is silent — and dropping that escape reports
 *   here, which is how the row earns its place.
 * - `otherMethod()` is not `createQueryBuilder()`, so the name guard declines before any type is read.
 */
final class RepositoryLike
{
    public function createQueryBuilder(): object
    {
        return new \stdClass();
    }

    public function otherMethod(): object
    {
        return new \stdClass();
    }
}

final class BuildsQueriesSafely
{
    public function onRepository(EntityRepository $repository): object
    {
        return $repository->createQueryBuilder('a');
    }

    public function onUnion(EntityManagerLike|RepositoryLike $either): object
    {
        return $either->createQueryBuilder();
    }

    public function onMixed(mixed $unknown): mixed
    {
        return $unknown->createQueryBuilder();
    }

    public function otherMethod(RepositoryLike $repository): object
    {
        return $repository->otherMethod();
    }
}
