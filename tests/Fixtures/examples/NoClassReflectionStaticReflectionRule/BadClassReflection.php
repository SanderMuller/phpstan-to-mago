<?php

declare(strict_types=1);

namespace Examples\Reflection;

use ReflectionClass;

final class BadClassReflection
{
    /** A class of this project, which Rector's own build does not autoload. */
    public function ownClass(): ReflectionClass
    {
        return new ReflectionClass(BadClassReflection::class);
    }

    /** The same name written as a string rather than through `::class`. */
    public function ownClassAsString(): ReflectionClass
    {
        return new ReflectionClass('Examples\Reflection\BadClassReflection');
    }

    /**
     * A `class-string` narrowed to a class outside the allowed prefixes.
     *
     * @param class-string<BadClassReflection> $className
     */
    public function narrowedClassString(string $className): ReflectionClass
    {
        return new ReflectionClass($className);
    }

    /** A `class-string` narrowed to nothing at all, which names no class the build autoloads. */
    public function anyClassString(): ReflectionClass
    {
        /** @var class-string $className */
        $className = 'Examples\Reflection\BadClassReflection';

        return new ReflectionClass($className);
    }
}
