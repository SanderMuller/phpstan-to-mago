<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Four shapes below the rule's bar, each varying one thing the finder tests.
 *
 * - Two `add` calls, not three. `MIN_ALERT_COUNT` is the axis; lower it and this reports.
 * - Three calls with three different names. The count is per name, not across the chain.
 * - Three `add` calls whose second argument holds *two* services. The finder wants an array of exactly one
 *   element, so a genuine multi-service call is not a repeated single-service adder.
 * - Three `add` calls passing a plain string rather than a `service()` or `ref()` reference. That row is
 *   caught by the "is it a call at all" test, not by the name test.
 * - Three `add` calls passing `helper()`, which *is* a function call and is not one of the two names
 *   `SymfonyFunctionName` holds. That is the row the name test needs: without it, dropping the name check
 *   entirely left the gate green, because the plain-string row never reached it.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->services()->set('two_only')
        ->call('add', [service('Examples\Config\First')])
        ->call('add', [service('Examples\Config\Second')]);

    $containerConfigurator->services()->set('varied')
        ->call('addFirst', [service('Examples\Config\First')])
        ->call('addSecond', [service('Examples\Config\Second')])
        ->call('addThird', [service('Examples\Config\Third')]);

    $containerConfigurator->services()->set('pairs')
        ->call('add', [service('Examples\Config\First'), service('Examples\Config\Second')])
        ->call('add', [service('Examples\Config\First'), service('Examples\Config\Third')])
        ->call('add', [service('Examples\Config\Second'), service('Examples\Config\Third')]);

    $containerConfigurator->services()->set('plain')
        ->call('add', ['Examples\Config\First'])
        ->call('add', ['Examples\Config\Second'])
        ->call('add', ['Examples\Config\Third']);

    $containerConfigurator->services()->set('other_function')
        ->call('add', [helper('Examples\Config\First')])
        ->call('add', [helper('Examples\Config\Second')])
        ->call('add', [helper('Examples\Config\Third')]);
};
