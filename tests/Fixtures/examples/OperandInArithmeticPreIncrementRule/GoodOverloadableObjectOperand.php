<?php

declare(strict_types=1);

namespace Examples\PreIncrement;

use GMP;
use SimpleXMLElement;
use SimpleXMLIterator;

/**
 * The two object hierarchies the rule does not report, and they are the control for `stdClass` next door.
 *
 * `BadNonNumericOperand` increments a `stdClass` and the rule reports it, which reads as "an object is a
 * finding". It is not: `ObjectType::toNumber()` answers `float|int` for `SimpleXMLElement` and `GMP` and
 * `ErrorType` for every other object, and `isValidForIncrement()` accepts anything whose `++` does not
 * error. Both of these increment cleanly, so PHPStan stays silent and so must the plugin.
 *
 * `SimpleXMLIterator` is here because the check is `isInstanceOf()`, not a name compare — a subclass is
 * covered, and a port matching on the exact two names would report this row.
 */
final class OverloadableObjectOperand
{
    public function overloadable(GMP $big, SimpleXMLElement $xml, SimpleXMLIterator $iterator): void
    {
        ++$big;
        ++$xml;
        ++$iterator;
    }
}
