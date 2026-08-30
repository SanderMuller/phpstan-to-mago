<?php

declare(strict_types=1);

namespace Examples\Localisation;

use Examples\Contracts\Auditable;
use Examples\Contracts\Localised;

/**
 * Two configured pairs violated by one class, which is two findings at one span.
 *
 * Both land on this class's line, because the rule reports at the class-like and not at the `use` statement
 * that caused it. So a port reporting once per class agrees with the real rule on every other example here
 * and disagrees only on this one.
 */
final class BadTwoTraitsOneClass
{
    use Auditable;
    use Localised;
}
