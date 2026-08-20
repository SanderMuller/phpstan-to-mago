<?php

declare(strict_types=1);

namespace Examples\Paths;

final class BadMissingFile
{
    public function config(): string
    {
        return __DIR__ . '/nothing-here.php';
    }
}
