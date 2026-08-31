<?php

declare(strict_types=1);

namespace Examples\Marking;

use Examples\Attribute\Sensitive;

/**
 * Not an attribute class, so its namespace is none of the rule's business — the guard the rule opens with.
 * It *carries* an attribute, which is the near miss: a port asking "does it have any attribute" rather than
 * "is it one" reports here and PHPStan does not.
 */
#[Sensitive('pii')]
final class NotAnAttributeItself
{
    public function value(): string
    {
        return '';
    }
}
