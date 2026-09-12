<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * `set(X)->class(X)` with the same argument written both times, which is the duplication the rule forbids.
 *
 * The two arguments are read by the same inlined helper called twice, and the comparison between them is
 * what the finding turns on. Both reads bound one local before this round, so the comparison was a value
 * against itself and constantly true — see VERIFICATION.md.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(DuplicatedService::class)->class(DuplicatedService::class);
};
