<?php

declare(strict_types=1);

namespace Examples\DuplicateArgAutowire;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/** `arg()` naming the very type the constructor already autowires — the rule's first report path. */
final class DuplicatedTypeConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(ArgWiredService::class)
                ->arg('$dependency', ref(ArgDependency::class));
        };
    }
}

final class ArgDependency {}

final class ArgWiredService
{
    public function __construct(private ArgDependency $dependency) {}
}
