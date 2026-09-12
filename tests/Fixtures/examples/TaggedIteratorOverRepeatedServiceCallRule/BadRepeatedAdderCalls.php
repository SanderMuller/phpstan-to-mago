<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Two reported statements, deliberately on different lines.
 *
 * That is the row the report anchor turns on: the rule calls `->line($stmt->getStartLine())`, so each finding
 * belongs to its own statement rather than to the closure. Anchor both on the hook node and the two findings
 * collapse onto the closure's line, which is a difference only two statements can show.
 *
 * The first chain repeats `add` three times through `service()`, the second through `ref()` — both are names
 * `SymfonyFunctionName` holds, and both count.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->services()->set('collector')
        ->call('add', [service('Examples\Config\First')])
        ->call('add', [service('Examples\Config\Second')])
        ->call('add', [service('Examples\Config\Third')]);

    $containerConfigurator->services()->set('registry')
        ->call('register', [ref('Examples\Config\First')])
        ->call('register', [ref('Examples\Config\Second')])
        ->call('register', [ref('Examples\Config\Third')]);
};
