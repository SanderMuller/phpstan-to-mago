<?php declare(strict_types=1);

namespace Examples\Callables;

/**
 * The written name, which mago spells `Variable > DirectVariable` — the same spelling a *dynamic* function
 * call's name has, which is why the written-name predicate reads the position rather than the part alone.
 */
final class GoodWrittenStaticProperty
{
    public function take(): string
    {
        return Holder::$prop;
    }
}
