<?php

declare(strict_types=1);

namespace Examples\Constructors;

/** A parent that sets something up in its constructor. */
class Registry
{
    protected array $entries = [];

    public function __construct()
    {
        $this->entries = ['default'];
    }
}

/** The override that never calls the parent, which is what the rule reports. */
final class SilentRegistry extends Registry
{
    public function __construct()
    {
        $this->entries = [];
    }
}

/** A middle class that declares no constructor of its own. */
class MiddleRegistry extends Registry {}

/**
 * The same override two levels down.
 *
 * PHPStan asks the *parent's* reflection whether it has a constructor, and a reflection inherits — so the
 * grandparent's counts. This is the case that says whether the port's codebase read walks the hierarchy too.
 */
final class DistantRegistry extends MiddleRegistry
{
    public function __construct()
    {
        $this->entries = ['distant'];
    }
}
