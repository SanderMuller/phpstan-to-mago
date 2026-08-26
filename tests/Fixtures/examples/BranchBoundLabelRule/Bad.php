<?php

declare(strict_types=1);

namespace Examples\BranchBound;

final class Service
{
    public function known(): void {}
}

final class BadCaller
{
    /** A written method name, so the branch takes the name side. */
    public function named(Service $service): void
    {
        $service->known();
    }

    /** A computed name, so the branch takes the rendered-type side and the message quotes the receiver. */
    public function computed(Service $service, string $method): void
    {
        $service->$method();
    }
}
