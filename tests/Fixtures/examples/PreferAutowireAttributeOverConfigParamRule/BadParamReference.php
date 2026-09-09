<?php

declare(strict_types=1);

namespace Examples\PreferAutowireAttribute;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** The other branch of the rule's predicate: a percent-wrapped literal rather than a `param()` call. */
final class ParamReferenceConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(LocalService::class)
                ->arg('$timeout', '%app.timeout%');
        };
    }
}

final class LocalService {}
