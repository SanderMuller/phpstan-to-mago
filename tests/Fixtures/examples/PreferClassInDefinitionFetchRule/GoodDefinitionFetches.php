<?php

declare(strict_types=1);

namespace Examples\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Three ways to be silent, and each one is a different guard in the rule.
 *
 * The class constant is the form the rule asks for. The service id is a string that names no class, which is
 * the reflection check the rule does before reporting. The last is a method outside the four definition
 * names, so the rule never looks at its argument at all.
 */
final class RegistersProperly
{
    public function __construct(private ContainerBuilder $containerBuilder) {}

    public function byConstant(): void
    {
        $this->containerBuilder->getDefinition(RegistersProperly::class);
    }

    public function byServiceId(): void
    {
        $this->containerBuilder->getDefinition('app.helper.core');
    }

    public function otherMethod(): void
    {
        $this->containerBuilder->getParameter('kernel.debug');
    }
}
