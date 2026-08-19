<?php

declare(strict_types=1);

namespace Examples\Flags;

final class Sender
{
    public function __construct(public string $to, public bool $urgent) {}

    /**
     * Written as `self`, which Mago resolves to nothing.
     *
     * `self` is a `Keyword` node, not a name, so `getResolvedName()` answers null where PHPStan's
     * `resolveName()` answers the enclosing class. The port answers it from the enclosing class instead, and
     * this call is what proves that branch runs rather than being written and never reached.
     */
    public static function urgent(string $to): self
    {
        return new self($to, true);
    }
}

final class Caller
{
    public function go(): void
    {
        new Sender('team', true);
    }
}
