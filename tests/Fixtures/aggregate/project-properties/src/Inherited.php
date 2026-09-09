<?php declare(strict_types=1);

namespace Coverage;

/**
 * A property the parent already declares with a type.
 *
 * One of the four behaviours `ACCEPTED_DIVERGENCE['properties']` records as measured before being relied
 * on: a property is typed when a *parent* class declares it, not only when it is written with a type here.
 */
class Inherited extends Typed
{
    public $name = 'inherited';
}
