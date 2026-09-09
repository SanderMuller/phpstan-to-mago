<?php

declare(strict_types=1);

namespace Examples\DuplicateArgsAutowire;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/**
 * `ref()` naming the very type the constructor already autowires, which the rule reports.
 *
 * `ref` is imported rather than written fully qualified, because that is how a config closure is written and
 * because the port compared the name as written until the resolution arm landed — a fully qualified spelling
 * here would pass while leaving the common one silently unreported.
 */
final class DuplicatedByTypeConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(WiredService::class)
                ->args([ref(Dependency::class)]);
        };
    }
}

final class Dependency {}

final class WiredService
{
    public function __construct(private Dependency $dependency) {}
}
