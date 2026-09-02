<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/**
 * A protected method two levels up, on an abstract class.
 *
 * Abstract because the rule reports a protected member on a concrete one, and it would report this
 * declaration rather than the override the file is about — measured: both engines did, which is what a good
 * example must not contain.
 */
abstract class DistantBase
{
    protected function shared(): string
    {
        return 'base';
    }
}

/** A middle class that declares nothing. */
class MiddleWidget extends DistantBase {}

/**
 * The override of a method the *grandparent* declares.
 *
 * PHPStan asks the parent's reflection for the method and a reflection inherits, so the original skips this
 * one. A port whose codebase read stops at the direct parent reports it, which is the wrong direction.
 */
final class DistantWidget extends MiddleWidget
{
    protected function shared(): string
    {
        return 'distant';
    }
}
