<?php

declare(strict_types=1);

namespace Examples\Inherited;

final class GoodCaller
{
    public function run(Service $service): void
    {
        $service->allowed();
    }
}
