<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Two ways to be silent, and the first is the control on the comparison.
 *
 * `set(A)->class(B)` names two different classes, which is the whole point of writing `->class()` — so both
 * engines pass over it. A port whose two argument reads shared one local would compare a value with itself,
 * find them equal, and report here: this file is what makes that a measurement rather than an argument.
 *
 * The second is a bare `set()` with no `->class()` at all, where the receiver is not a method call.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(AliasedService::class)->class(DifferentImplementation::class);

    $services->set(PlainService::class);
};
