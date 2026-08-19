<?php

declare(strict_types=1);

namespace Examples\Forwarding;

final class Caller
{
    public function go(): void
    {
        forbidden();
    }
}
