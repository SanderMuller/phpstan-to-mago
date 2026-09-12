<?php

declare(strict_types=1);

namespace Examples\DuplicateArgsAutowire;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/** A reference to a type the constructor does not take, so autowiring would not have supplied it. */
final class DistinctTypeConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(OtherWiredService::class)
                ->args([ref(Unrelated::class)]);
        };
    }
}

final class Unrelated {}

final class OtherWiredService
{
    public function __construct(private Dependency $dependency) {}
}
