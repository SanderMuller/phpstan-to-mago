<?php

declare(strict_types=1);

namespace Aggregate\Returns;

/** A declared return type: counted, and never reported. */
final class Typed
{
    public function done(): int
    {
        return 1;
    }
}
