<?php

declare(strict_types=1);

namespace Examples\LeadingBackslash;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

final class BadLeadingBackslash
{
    /**
     * Written as the imported short name, which is the spelling that makes the comparison load-bearing.
     *
     * PHPStan compares `$node->class->toString()` against `Name::class`, and php-parser has already rewritten
     * the name through this file's `use`. Mago keeps it as written, so a port comparing the *written* text to
     * a fully-qualified list is silent here — on exactly the code the rule exists to catch.
     */
    public function imported(): Name
    {
        return new Name('\Foo');
    }

    /** The fully-qualified spelling, which is a written name of its own kind rather than a bare one. */
    public function qualified(): FullyQualified
    {
        return new FullyQualified('\Bar');
    }
}
