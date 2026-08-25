<?php

declare(strict_types=1);

namespace Examples\StrictConstructs;

final class GoodBacktick
{
    /** The function the rule asks for instead. A string holding a backtick is not the operator. */
    public function listing(): string|false|null
    {
        return shell_exec('ls -la `pwd`');
    }
}
