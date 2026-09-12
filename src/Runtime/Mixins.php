<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/**
 * What a `@mixin` puts on a class, which is part of every question PHPStan answers with `hasMethod()`.
 *
 * PHPStan's `MixinMethodsClassReflectionExtension` is in **core**, not in larastan, and it answers
 * `ClassReflection::hasMethod()`, `getMethod()` and therefore every rule that asks whether a class has a
 * method. Mago publishes `ClassLikeMetadata->mixins`, so the question is answerable on this side too — which
 * is worth saying plainly, because this repository recorded the whole effect as unportable reflection for as
 * long as it went unmeasured, and the wrong half was the larger half.
 *
 * Three properties, each controlled rather than reasoned about, and each written down before it ran:
 *
 * - **A mixin is inherited.** `hasMethod()` on a class sees a `@mixin` written on its *parent*, so the seed
 *   is the class plus its declared ancestry rather than the class alone. Controlled with a three-link chain
 *   where the mixin sits on the grandparent.
 * - **It is transitive.** A mixin target's own `@mixin` counts, which is the shape `laravel/framework`
 *   writes: `Relation` is `@mixin Builder` and `Builder` is `@mixin \Illuminate\Database\Query\Builder`.
 * - **An unresolvable target puts nothing on anything.** `@mixin \Predis\Client` with predis absent locks
 *   nothing, and the first hypothesis for Laravel's over-count was exactly that it did.
 *
 * What this cannot close is a target whose metadata is thinner than the runtime's: `@mixin \Redis` on
 * `Illuminate\Redis\Connections\Connection` gives mago `scan`, `sscan` and `zscan` and not `hscan`.
 *
 * @see DeclaredParameters::throughMixins() for the collector's LSP guard, which asks the same question of a
 *      list of ancestors
 */
final class Mixins
{
    /**
     * The method a class has, whether it inherits it or a `@mixin` brings it.
     *
     * `getDeclaringMethod()` first, because that answers the hierarchy and is what almost every call wants;
     * the mixin walk runs only where it found nothing, so nothing already agreeing can change.
     */
    public static function declaringMethod(Codebase $codebase, ?string $class, ?string $method): ?FunctionLikeMetadata
    {
        if ($class === null || $method === null || $class === '' || $method === '') {
            return null;
        }

        $declared = $codebase->getDeclaringMethod($class, $method);
        if ($declared instanceof FunctionLikeMetadata) {
            return $declared;
        }

        foreach (self::targetsOf($codebase, [$class, ...self::ancestryOf($codebase, $class)]) as $target) {
            $declared = $codebase->getDeclaringMethod($target, $method);
            if ($declared instanceof FunctionLikeMetadata) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * Every class a `@mixin` on one of these names puts methods on, transitively, and their ancestry.
     *
     * The seeds themselves are not returned: a caller either has them already or is asking exactly what the
     * mixins add. Each target's declared ancestry is folded in because `hasMethod()` on the target consults
     * the target's own hierarchy.
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    public static function targetsOf(Codebase $codebase, array $classes): array
    {
        $seen = [];
        foreach ($classes as $class) {
            $seen[strtolower($class)] = true;
        }

        $queue = $classes;
        $targets = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            $metadata = $codebase->getClassLike($name);
            if (! $metadata instanceof ClassLikeMetadata) {
                continue;
            }

            foreach (self::mixinNames($metadata) as $mixin) {
                if (isset($seen[strtolower($mixin)])) {
                    continue;
                }

                $seen[strtolower($mixin)] = true;
                $targets[] = $mixin;
                $queue = [...$queue, $mixin, ...self::ancestryOf($codebase, $mixin)];
            }
        }

        return $targets;
    }

    /**
     * The class names a class-like's `@mixin` lines resolve to.
     *
     * A generic argument is written on plenty of them — `@mixin Builder<TRelatedModel>` on `Relation` — and
     * the name is the whole of what the question needs.
     *
     * @return list<string>
     */
    private static function mixinNames(ClassLikeMetadata $metadata): array
    {
        $names = [];
        foreach ($metadata->mixins as $mixin) {
            foreach ($mixin->atomicTypes as $atomic) {
                if ($atomic instanceof NamedObjectType) {
                    $names[] = $atomic->name;
                }
            }
        }

        return $names;
    }

    /**
     * A named class's declared ancestry, both directions of it, or nothing when the name does not resolve.
     *
     * @return list<string>
     */
    private static function ancestryOf(Codebase $codebase, string $class): array
    {
        $metadata = $codebase->getClassLike($class);

        return $metadata instanceof ClassLikeMetadata
            ? [...$metadata->parentClasses, ...$metadata->parentInterfaces]
            : [];
    }
}
