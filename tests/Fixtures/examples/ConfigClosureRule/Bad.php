<?php

declare(strict_types=1);

namespace Examples\Config;

final class Configurator
{
    public function services(): void {}
}

final class Registrar
{
    public function register(): callable
    {
        return static function (Configurator $configurator): void {
            $configurator->services();
        };
    }
}
