<?php

declare(strict_types=1);

namespace Examples\Setters;

/**
 * The same trait shape with the one axis varied: the only class using it is an enum.
 *
 * So the users' kinds decide, and both engines stay silent — PHPStan because the reflection it analyses the
 * member under is the enum's, the port because no using class is a `Class`. Folding a trait to "is a class"
 * would report here, which is why the branch asks the users rather than treating a trait as one.
 */
trait EnumOnlySetterTrait
{
    public function setLabel(string $label): string
    {
        return $label;
    }
}

enum Labelled: string
{
    use EnumOnlySetterTrait;

    case First = 'first';
}
