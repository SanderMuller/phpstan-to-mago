<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The four shapes the rule allows: the class alone, a name that differs from it, a call on a differently
 * named variable, and a call whose receiver is not a variable at all.
 */
final class DistinctNames
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $services = $containerConfigurator->services();

            $services->set(Widget::class);
            $services->set(Gadget::class, Widget::class);

            $other = $containerConfigurator->services();
            $other->set(Widget::class, Widget::class);

            // The receiver is a call rather than `$services`, so `NamingHelper::getName()` answers null on
            // it and the rule declines — the shape that makes the written-name read return null rather than
            // a node's source text.
            $containerConfigurator->services()->set(Gadget::class, Gadget::class);
        };
    }
}
