<?php

declare(strict_types=1);

namespace Examples\Config;

final class Defaults
{
    public const string NAME = 'defaults';

    public function name(): string
    {
        return self::NAME;
    }
}
