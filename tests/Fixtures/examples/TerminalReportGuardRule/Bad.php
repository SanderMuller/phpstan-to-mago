<?php

declare(strict_types=1);

namespace Examples\TerminalReport;

final class Service
{
    public function forbidden(): void {}

    public function allowed(): void {}
}

final class BadCaller
{
    public function run(Service $service): void
    {
        $service->forbidden();
    }
}
