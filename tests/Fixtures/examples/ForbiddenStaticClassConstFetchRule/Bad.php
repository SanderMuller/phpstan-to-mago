<?php

declare(strict_types=1);

namespace Examples\Config;

class Settings
{
    public const string NAME = 'settings';

    public function name(): string
    {
        return static::NAME;
    }
}
