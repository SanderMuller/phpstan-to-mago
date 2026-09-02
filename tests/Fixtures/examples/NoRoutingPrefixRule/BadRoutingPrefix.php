<?php

declare(strict_types=1);

namespace Examples\Routing;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/** Prefixing an import of this project's own controllers, which the rule asks to be written per route. */
final class BadRoutingPrefix
{
    public function admin(RoutingConfigurator $routes): void
    {
        $routes->import('../src/Controller/Admin/', 'attribute')
            ->prefix('/admin');
    }

    /** A second one, so the pair proves the rule reports per call rather than once per file. */
    public function api(RoutingConfigurator $routes): void
    {
        $routes->import('../src/Controller/Api/', 'attribute')
            ->prefix('/api');
    }
}
