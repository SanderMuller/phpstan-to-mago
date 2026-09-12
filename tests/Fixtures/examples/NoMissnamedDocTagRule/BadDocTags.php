<?php

declare(strict_types=1);

namespace Examples\DocTags;

/**
 * One wrong tag per member kind, because the rule walks methods, properties and constants separately and
 * reports a different message for each.
 *
 * The captured tag is in the message, so each finding also measures the group read: the port re-runs the
 * pattern to get it rather than holding the match array, and a group that came back empty would show as a
 * message with nothing between the quotes.
 *
 * In pint's `notPath`: `phpdoc_var_without_name` strips the `$wrong` from the method's `@var`, which the
 * rule does not care about but which makes the fixture no longer the shape it was written as.
 */
final class MisnamedTags
{
    /** @param string $wrong */
    public const WRONG_ON_CONSTANT = 'x';

    /** @return string */
    public string $wrongOnProperty = '';

    /** @var string $wrong */
    public function wrongOnMethod(string $given): string
    {
        return $given;
    }
}
