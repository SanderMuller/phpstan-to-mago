<?php

declare(strict_types=1);

namespace Examples\Inheritance;

/**
 * The three ways the rule stays silent.
 *
 * - `renderIt()` matches the parent's spelling exactly, which is the whole question.
 * - `describeIt()` matches the interface's.
 * - `ownMethod()` is declared nowhere above, so there is no inherited name to disagree with.
 */
final class MatchingCase extends NamingBase implements NamesThings
{
    public function renderIt(): string
    {
        return 'derived';
    }

    public function describeIt(): string
    {
        return 'described';
    }

    public function ownMethod(): string
    {
        return 'own';
    }
}
