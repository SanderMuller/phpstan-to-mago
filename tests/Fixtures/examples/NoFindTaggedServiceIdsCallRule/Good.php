<?php

declare(strict_types=1);

namespace Examples\DependencyInjection;

final class AutoconfiguringPass
{
    public function process(object $container): void
    {
        $this->registerForAutoconfiguration('app.handler');
    }

    public function registerForAutoconfiguration(string $tag): void {}
}
