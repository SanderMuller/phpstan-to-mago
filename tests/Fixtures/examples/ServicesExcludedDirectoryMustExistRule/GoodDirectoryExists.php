<?php

declare(strict_types=1);

namespace Examples\ServicesExcludedDirectory;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class PresentDirectoryConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->exclude([__DIR__ . '/Present']);
        };
    }
}
