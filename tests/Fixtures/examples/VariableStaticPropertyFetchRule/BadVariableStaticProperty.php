<?php declare(strict_types=1);

namespace Examples\Callables;

/** `Holder::$$name` and `$class::$$name` — a computed static property name, both spellings of the class. */
final class BadVariableStaticProperty
{
    /** @return array<mixed> */
    public function take(string $name, string $class): array
    {
        return [Holder::$$name, $class::$$name];
    }
}
