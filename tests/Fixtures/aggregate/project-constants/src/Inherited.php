<?php declare(strict_types=1);

namespace Coverage;

/**
 * Redeclares the parent's constant. The collector counts it and never reports it: a constant a parent class
 * already declares is guarded, whether or not either of them carries a type.
 */
final class Inherited extends Typed
{
    public const NAME = 'inherited';
}
