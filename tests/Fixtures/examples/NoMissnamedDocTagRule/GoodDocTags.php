<?php

declare(strict_types=1);

namespace Examples\DocTags;

/**
 * The right tag on each member kind, plus a member with no docblock at all.
 *
 * A constant and a property take `@var`; a method takes `@param` or `@return`. The undocumented method is
 * the guard ahead of the pattern: `getDocComment()` answers null there and the loop moves on, so a port
 * reading the pattern against nothing would report on every member that carries no docblock.
 *
 * In pint's `notPath`, and that is load-bearing. `no_superfluous_phpdoc_tags` deleted the property's `@var`
 * and the method's `@param` as redundant against the native types, which left two of these four cases
 * silent for the wrong reason — no docblock rather than the right tag — with the suite still green. The
 * same fixer class already cost this repository an array-callable case; see VERIFICATION.md.
 */
final class WellNamedTags
{
    /** @var string */
    public const RIGHT_ON_CONSTANT = 'x';

    /** @var string */
    public string $rightOnProperty = '';

    /** @param string $given */
    public function rightOnMethod(string $given): string
    {
        return $given;
    }

    public function undocumented(): string
    {
        return '';
    }
}
