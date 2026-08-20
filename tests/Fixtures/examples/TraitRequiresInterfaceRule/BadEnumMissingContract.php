<?php

declare(strict_types=1);

namespace Examples\Localisation;

use Examples\Contracts\Localised;

/**
 * An enum, deliberately. PHPStan's `InClassNode` fires for enums, classes and interfaces — controlled with a
 * rule reporting unconditionally — where Mago's `Class` hook fires for the class alone. A rule that does not
 * narrow to `Class_` therefore needs all three targets, and this file is what makes that visible: the pairs
 * this rule exists for in a real project are enum concerns.
 */
enum BadEnumMissingContract: string
{
    use Localised;

    case Dutch = 'nl';
}
