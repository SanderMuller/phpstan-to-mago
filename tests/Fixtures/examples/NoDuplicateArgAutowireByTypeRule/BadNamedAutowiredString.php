<?php

declare(strict_types=1);

namespace Examples\DuplicateArgAutowire;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/**
 * The rule's *second* report path: a named autowired string rather than a type match.
 *
 * That branch sits after the first one and is reached by falling out of it, which is the shape the port
 * refused until a real block was emitted instead of folding the branch into the guard chain. Without this
 * file the first path alone is exercised, and a port that returned early would pass the gate green.
 */
final class NamedAutowiredStringConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(ArgWiredService::class)
                ->arg('$requestStack', ref('request_stack'));
        };
    }
}
