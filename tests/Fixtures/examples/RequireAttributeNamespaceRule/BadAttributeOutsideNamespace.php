<?php

declare(strict_types=1);

namespace Examples\Marking;

/** An attribute class outside an `Attribute` namespace, which is what the rule asks for. */
#[\Attribute]
final class Sensitive
{
    public function __construct(public string $reason = '') {}
}
