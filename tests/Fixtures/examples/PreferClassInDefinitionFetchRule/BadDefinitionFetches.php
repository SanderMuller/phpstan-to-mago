<?php

declare(strict_types=1);

namespace Examples\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A container definition fetched by a string that really names a class, which is what the rule forbids.
 *
 * The class named has to exist, because the rule asks the reflection provider before reporting — so the
 * string here is a real class from this tree rather than an invented one. The finding lands on the
 * *argument*, which the rule sets with `->line($firstArg->getStartLine())`.
 *
 * The first method is the control and is silent: it passes the class constant, which is the form the rule
 * asks for, so a port reporting on it would be reporting on the fix.
 */
final class RegistersByString
{
    public function __construct(private ContainerBuilder $containerBuilder) {}

    public function passesTheConstant(): void
    {
        $this->containerBuilder->getDefinition(RegistersByString::class);
    }

    public function fetchByString(): void
    {
        $this->containerBuilder->getDefinition('Examples\Symfony\RegistersByString');
    }

    public function askByString(): void
    {
        $this->containerBuilder->hasDefinition('Examples\Symfony\RegistersByString');
    }
}
