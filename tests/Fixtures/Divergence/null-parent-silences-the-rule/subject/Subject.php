<?php

declare(strict_types=1);

namespace Divergence\NullParentSilencesTheRule;

use ArrayObject;
use Totally\Unresolvable\Base;

/**
 * A parent nothing in this case resolves.
 *
 * PHPStan runs the rule and hands it a valid `ClassReflection` whose `getParentClass()` answers null, so
 * `fast_has_parent_constructor()` is false and the rule returns nothing. The silence is the rule declining,
 * not the rule being absent — which is the whole point, and why the control below exists.
 */
final class UnresolvableParent extends Base
{
    public function __construct() {}
}

/**
 * The control: the same shape with a parent that resolves and has a constructor.
 *
 * Without it the silence above is untested, because a rule that never fires looks identical to one that
 * fired and declined.
 */
final class ResolvableParent extends ArrayObject
{
    public function __construct() {}
}
