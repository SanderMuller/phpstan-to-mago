<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class BadBacktick
{
    /**
     * The backtick operator, which the rule forbids outright.
     *
     * The file is in pint's `notPath`: `backtick_to_shell_exec` rewrites this into the very call the rule
     * asks for, and the suite then stays green with nothing left to find.
     */
    public function listing(): mixed
    {
        return `ls -la`;
    }
}
