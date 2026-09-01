<?php

declare(strict_types=1);

namespace Examples\ValueObject;

/** A value object, recognised by the `ValueObject` segment in its resolved name. */
final class Money
{
    public function __construct(public int $cents) {}
}
