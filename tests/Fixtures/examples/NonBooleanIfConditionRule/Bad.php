<?php

declare(strict_types=1);

namespace Examples\NonBooleanIf;

interface Alpha {}

interface Beta {}

final class BadConditions
{
    /** An int condition: the type goes into the message, so both tools have to render it the same way. */
    public function counted(int $count): void
    {
        if ($count) {
            echo 1;
        }
    }

    /** A nullable class, where PHPStan puts the class before `null` and `Type::__toString()` agrees. */
    public function maybe(?Alpha $alpha): void
    {
        if ($alpha) {
            echo 2;
        }
    }

    /**
     * An intersection, which is the shape `Type::__toString()` renders as its first member alone.
     *
     * @param Alpha&Beta $both
     */
    public function intersected(object $both): void
    {
        if ($both) {
            echo 3;
        }
    }

    /** A nullable *scalar*, which is the member order divergence: `int|null`, not `null|int`. */
    public function maybeCounted(?int $count): void
    {
        if ($count) {
            echo 4;
        }
    }
}
