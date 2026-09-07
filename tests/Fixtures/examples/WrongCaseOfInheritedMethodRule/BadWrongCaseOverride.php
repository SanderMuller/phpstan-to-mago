<?php

declare(strict_types=1);

namespace Examples\Inheritance;

interface Labels
{
    public function labelIt(): string;
}

interface NamesThings extends Labels
{
    public function describeIt(): string;
}

abstract class NamingBase
{
    public function renderIt(): string
    {
        return 'base';
    }
}

/**
 * Two overrides whose case differs from what they inherit — one from a parent, one from an interface.
 *
 * PHP matches method names case-insensitively, so all three compile and all three override. The message names
 * which kind of ancestor it is, so the rows separate the `parent` wording from the `interface` wording.
 *
 * `labelit()` overrides a method declared on `Labels`, which this class reaches only *through* `NamesThings`.
 * PHPStan's `getInterfaces()` is the transitive set, so it is found; reading `directParentInterfaces` instead
 * misses it, and that row is the only thing separating the two.
 */
final class WrongCaseOverrides extends NamingBase implements NamesThings
{
    public function renderit(): string
    {
        return 'derived';
    }

    public function describeit(): string
    {
        return 'described';
    }

    public function labelit(): string
    {
        return 'labelled';
    }
}
