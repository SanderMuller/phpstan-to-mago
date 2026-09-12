<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The name matches, plus the two ways the resolver answers null. Silent in both engines.
 *
 * The file is `GoodMatchingExtension.php`, so `GoodMatchingExtension` is the matching name.
 *
 * `lastStringWins` declares the match as the *second* argument of one call. The callback's `foreach` assigns
 * without breaking, so the last string contributes; a port taking the first would read `mismatch` and report.
 *
 * `nullsafeCall` is a `NullSafeMethodCall` in mago and a different class from `MethodCall` in php-parser, so
 * neither engine sees an `extension()` call — include that kind and this reports. The parameter is
 * deliberately **not** nullable even though the call is: written `?ContainerConfigurator` the detector rejects
 * the closure on the hint before the walk ever runs, so the row varied two axes and controlled neither.
 * Measured: with the nullable hint the mutation that matches nullsafe calls passes, and without it that
 * mutation fails.
 *
 * `noExtensionCall` has nothing to find, which is the plain null.
 */
final class MatchingConfigs
{
    /** @return list<callable> */
    public function closures(): array
    {
        return [
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator->extension('GoodMatchingExtension');
            },
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator->extension('mismatch', 'GoodMatchingExtension');
            },
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator?->extension('mismatch');
            },
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator->services();
            },
        ];
    }
}
