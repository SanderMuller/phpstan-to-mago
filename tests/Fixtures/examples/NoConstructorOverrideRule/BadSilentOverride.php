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
