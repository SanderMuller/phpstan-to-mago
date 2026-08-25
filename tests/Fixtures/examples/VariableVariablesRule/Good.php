<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class GoodVariableVariables
{
    /** A written name, which is the shape the rule allows — including the inner one of a forbidden pair. */
    public function assign(string $name): string
    {
        $written = $name;

        return $written;
    }
}
