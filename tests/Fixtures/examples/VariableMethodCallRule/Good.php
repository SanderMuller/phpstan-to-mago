<?php

declare(strict_types=1);

namespace Examples\VariableMethodCall;

final class GoodCaller
{
    /** A written method name, which is what the rule allows. */
    public function plain(Thing $thing): void
    {
        $thing->known();
    }
}
