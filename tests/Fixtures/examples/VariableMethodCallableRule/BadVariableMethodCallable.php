<?php declare(strict_types=1);

namespace Examples\Callables;

/** `$o->$name(...)` — first-class callable syntax with a computed method name. */
final class BadVariableMethodCallable
{
    public function take(Holder $holder, string $name): callable
    {
        return $holder->$name(...);
    }
}
