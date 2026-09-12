<?php

declare(strict_types=1);

namespace Examples\Setters;

/**
 * The kind guard, varied on the one axis it is about.
 *
 * The rule asks `$scope->getClassReflection()->isClass()`, which is a question about the class-like *around*
 * the method rather than about the method. Both members below return a value from a `set`-prefixed method
 * and both engines stay silent, because an interface is not a class and neither is an enum.
 *
 * It is the control for the guard that shipped inverted: asked of the method's own node the comparison is
 * false for every method ever written, which is a plugin that loads and reports nothing rather than one that
 * reports the wrong thing. `BadReturningSetters.php` is the other half — remove the guard and this file
 * reports, weaken it to the node and that one goes quiet.
 */
interface SetterContract
{
    public function setName(string $name): self;
}

enum SetterSuit: string
{
    case Hearts = 'H';

    public function setName(string $name): string
    {
        return $name;
    }
}
