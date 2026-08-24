<?php

declare(strict_types=1);

namespace Examples\LeadingBackslash;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

final class GoodNoLeadingBackslash
{
    /** No leading backslash, so there is nothing to rewrite. */
    public function plain(): Name
    {
        return new Name('Foo');
    }

    /** `FullyQualified` is what the rule asks the author to reach for. */
    public function alreadyQualified(): FullyQualified
    {
        return new FullyQualified('Bar');
    }

    /** A class the list does not name, so a leading backslash in its argument is not this rule's business. */
    public function unrelated(): Unrelated
    {
        return new Unrelated('\Baz');
    }
}

final class Unrelated
{
    public function __construct(private string $value) {}
}
