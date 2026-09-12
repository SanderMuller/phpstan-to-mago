<?php

declare(strict_types=1);

namespace Examples\PreferAutowireAttribute;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/**
 * The finding reached through an *imported* `param()`, which is how anybody writes it.
 *
 * The rule compares the callee against the fully qualified name, so this case only agrees with PHPStan once
 * the port follows the file's `use function` imports. Written fully qualified it passed while imported it did
 * not, and keeping only the qualified spelling would have bought a green gate for a rule silently blind to
 * the common form.
 */
final class ParamFunctionConfig
{
    public function configure(): callable
    {
        return static function (ContainerConfigurator $containerConfigurator): void {
            $containerConfigurator->services()
                ->set(ThirdLocalService::class)
                ->arg('$timeout', param('app.timeout'));
        };
    }
}

final class ThirdLocalService {}
