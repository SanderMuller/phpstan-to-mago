<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** A service registered with its own class as the name, which is what the rule reports. */
final class DuplicatedName
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $services = $containerConfigurator->services();

            $services->set(Widget::class, Widget::class);
            $services->set(Gadget::class, Gadget::class);
            // Two spellings of one class, which PHPStan resolves to the same name before the rule sees
            // either — so this is as much a duplicate as the first call. Written `namespace\Widget` rather
            // than fully qualified because the formatter rewrites `\Examples\Wiring\Widget::class` back to
            // the short form, and the case would quietly stop being one.
            $services->set(Widget::class, namespace\Widget::class);

            // And the three names PHPStan does *not* resolve. `self` stays `self`, so the original compares
            // it against `DuplicatedName` and declines; a port resolving the keyword reports a duplicate
            // nobody asked about.
            $services->set(self::class, DuplicatedName::class);
        };
    }
}

final class Widget {}

final class Gadget {}
