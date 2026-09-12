<?php

declare(strict_types=1);

namespace Examples\Constructors;

/** No parent at all, so there is nothing to override. */
final class Standalone
{
    public function __construct(private readonly string $name) {}
}

/** A parent that declares no constructor, which is the second thing the rule checks. */
class Marker {}

final class MarkedThing extends Marker
{
    public function __construct(private readonly int $size) {}
}

/** And the anonymous class, which the original skips with a comment saying so. */
final class Factory
{
    public function make(): Registry
    {
        return new class extends Registry {
            public function __construct()
            {
                $this->entries = ['anonymous'];
            }
        };
    }
}
