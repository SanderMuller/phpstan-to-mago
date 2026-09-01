<?php

declare(strict_types=1);

namespace Examples\Reflection;

use PhpParser\Node;
use PHPStan\Type\ObjectType;

final class GoodClassReflection
{
    /** A php-parser class, which the prefix regex allows. */
    public function phpParserClass(): \ReflectionClass
    {
        return new \ReflectionClass(Node\Stmt\Class_::class);
    }

    /** A PHPStan class, reached as an object rather than as a class string. */
    public function phpstanObject(ObjectType $type): \ReflectionClass
    {
        return new \ReflectionClass($type);
    }

    /**
     * A `class-string` narrowed to an allowed class, which the generic branch unwraps.
     *
     * @param class-string<Node> $className
     */
    public function narrowedClassString(string $className): \ReflectionClass
    {
        return new \ReflectionClass($className);
    }

    /** Two arguments, so the rule's count guard declines before it looks at anything else. */
    public function notOneArgument(): \ReflectionMethod
    {
        return new \ReflectionMethod(GoodClassReflection::class, 'notOneArgument');
    }

    /** One argument and a disallowed class, but a different reflection class. */
    public function notReflectionClass(): \ReflectionObject
    {
        return new \ReflectionObject($this);
    }
}
