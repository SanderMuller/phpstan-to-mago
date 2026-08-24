<?php

declare(strict_types=1);

namespace Examples\ArgumentKinds;

final class Marker
{
    public const string NAME = 'marker';
}

function ref(): string
{
    return 'ref';
}

final class Configurator
{
    public function configure(mixed $one, mixed $two): void {}

    /** A plain function call and a `Foo::BAR` access, so both predicates hold. */
    public function set(): void
    {
        $this->configure(ref(), Marker::NAME);
    }
}
