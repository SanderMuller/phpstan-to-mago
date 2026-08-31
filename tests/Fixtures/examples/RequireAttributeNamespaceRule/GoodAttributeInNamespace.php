<?php

declare(strict_types=1);

namespace Examples\Attribute;

/** The same class where the rule wants it: a namespace segment called `Attribute`. */
#[\Attribute]
final class Sensitive
{
    public function __construct(public string $reason = '') {}
}
