<?php declare(strict_types=1);

namespace Examples\Callables;

final class GoodWrittenStaticMethodCallable
{
    public function take(): callable
    {
        return Holder::make(...);
    }
}
