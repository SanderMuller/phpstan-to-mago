<?php declare(strict_types=1);

namespace Examples\Callables;

final class GoodWrittenStaticMethodCall
{
    public function take(): void
    {
        Holder::make();
    }
}
