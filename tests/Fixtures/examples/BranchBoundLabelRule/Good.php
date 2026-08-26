<?php

declare(strict_types=1);

namespace Examples\BranchBound;

final class GoodCaller
{
    /** The rule reports every method call, so a good example has none: a static call is a different kind. */
    public function statically(): void
    {
        Service::class;
    }
}
