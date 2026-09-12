<?php

declare(strict_types=1);

namespace Examples\PreferAutowireAttribute;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** A literal argument with no parameter reference, so there is nothing to prefer an attribute over. */
final class PlainArgumentConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(OtherLocalService::class)
                ->arg('$timeout', 30);
        };
    }
}

final class OtherLocalService {}
