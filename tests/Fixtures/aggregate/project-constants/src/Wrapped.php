<?php declare(strict_types=1);

namespace Coverage;

/**
 * One statement written over three lines, with a brace in the first default.
 *
 * It counts once, and the line reported is the one the `const` keyword is on — not the line either name is
 * on. Anchoring a finding on the name puts it two lines below where the real rule puts it.
 */
final class Wrapped
{
    public const
        DYNAMIC = 'Welcome {name}',
        STATIC_TEXT = 'Welcome';
}
