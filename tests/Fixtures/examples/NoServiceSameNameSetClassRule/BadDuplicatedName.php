<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** A service registered with its own class as the name, twice over, which is what the rule reports. */
final class DuplicatedName
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $services = $containerConfigurator->services();

            $services->set(Widget::class, Widget::class);
            $services->set(Gadget::class, Gadget::class);
        };
    }
}

final class Widget {}

final class Gadget {}
