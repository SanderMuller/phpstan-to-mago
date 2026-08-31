<?php declare(strict_types=1);

namespace Examples\Callables;

/**
 * Both spellings of the class side: a written name, which the rule resolves, and an expression, which it
 * describes as a type. The two are the arms of one `if`, so an example with only the first leaves half of the
 * message unexercised.
 */
final class BadVariableStaticMethodCallable
{
    /** @return array<callable> */
    public function take(string $name, string $class): array
    {
        return [Holder::$name(...), $class::$name(...)];
    }
}
