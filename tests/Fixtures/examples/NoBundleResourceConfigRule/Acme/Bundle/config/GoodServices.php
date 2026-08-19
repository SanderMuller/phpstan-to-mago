<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The same config closure at a path the rule accepts, so the pair separates the closure test from the path test.
 *
 * The rule reports only under `Resources/config`; this one sits under `config`. That is why the gate copies
 * examples keeping their directories — flattened, both files would land beside each other and the rule could
 * never say no to one of them.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->services();
};
