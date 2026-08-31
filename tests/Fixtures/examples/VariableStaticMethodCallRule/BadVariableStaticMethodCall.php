<?php declare(strict_types=1);

namespace Examples\Callables;

/** The ordinary static call, with the class side written both ways. */
final class BadVariableStaticMethodCall
{
    public function take(string $name, string $class): void
    {
        Holder::$name();
        $class::$name();
    }
}
