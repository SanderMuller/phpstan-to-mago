<?php

declare(strict_types=1);

namespace Examples\Attributes;

use Attribute;

#[Attribute]
final class Marker
{
    public function __construct(public readonly string $note) {}
}

#[Attribute]
final class BadRoute
{
    /**
     * A positional argument on an attribute.
     *
     * The `#[Attribute]` markers above are deliberate. The rule skips PHP's own marker by name, and that
     * skip is where the port compared against the rule's own namespace instead of the global class -- so it
     * matched nothing, skipped nothing, and reported here for the wrong reason.
     */
    #[Marker('gone in 2.0')]
    public function handle(): void {}
}
