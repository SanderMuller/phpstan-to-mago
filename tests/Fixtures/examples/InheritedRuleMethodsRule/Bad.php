<?php

declare(strict_types=1);

namespace Examples\Inherited;

final class BadCaller
{
    public function run(Service $service): void
    {
        $service->forbidden();
    }
}

final class Service
{
    public function forbidden(): void {}

    public function allowed(): void {}
}
