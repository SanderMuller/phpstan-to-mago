<?php

declare(strict_types=1);

namespace Examples\Calls;

final class Both
{
    public function forbidden(): void {}

    /**
     * All three kinds the guard keeps. The nullsafe one is the interesting member: PHPStan desugars `?->`
     * into a `MethodCall`, so `instanceof MethodCall` holds for it and the original reports it — where Mago
     * keeps the two kinds apart and the port has to name both to agree.
     */
    public function call(?self $other): void
    {
        $other->forbidden();
        self::forbidden();
        $other?->forbidden();
    }
}
