<?php

declare(strict_types=1);

namespace Examples\Routing;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class GoodRoutingPrefix
{
    /** An external bundle, which the rule allows by the literal path the import was written with. */
    public function framework(RoutingConfigurator $routes): void
    {
        $routes->import('@FrameworkBundle/Resources/config/routing/errors.xml')
            ->prefix('/_error');
    }

    /** The other allowed prefix, so both branches of the bundle test are covered. */
    public function profiler(RoutingConfigurator $routes): void
    {
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.xml')
            ->prefix('/_profiler');
    }

    /**
     * A collection rather than an import.
     *
     * `CollectionConfigurator` declares `prefix()` too, so the name matches and only the receiver's type
     * declines it — which is what says the type guard is doing the work rather than the name.
     */
    public function collection(RoutingConfigurator $routes): void
    {
        $routes->collection('admin')
            ->prefix('/admin');
    }

    /** The same name on something that is not a routing configurator at all. */
    public function unrelated(): void
    {
        (new Prefixer())->prefix('/admin');
    }
}

final class Prefixer
{
    public function prefix(string $prefix): void {}
}
