<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class BadVariableVariables
{
    /** `$$name` is a variable whose name is another variable, which the rule forbids. */
    public function assign(string $name): void
    {
        $$name = 1;
    }

    /** `${..}` is the other shape of the same thing — a different node kind, the same question. */
    public function read(string $name): mixed
    {
        return ${$name . 'Suffix'};
    }
}
