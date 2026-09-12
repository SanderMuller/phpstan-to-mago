<?php

declare(strict_types=1);

namespace Examples\DuplicateArgAutowire;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/** Neither path: the referenced type is not the constructor's, and the string is not a named autowired one. */
final class DistinctArgTypeConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(ArgWiredService::class)
                ->arg('$dependency', ref(ArgUnrelated::class));

            $containerConfigurator->services()
                ->set(ArgWiredService::class)
                ->arg('$label', ref('plain_value'));
        };
    }
}

final class ArgUnrelated {}
