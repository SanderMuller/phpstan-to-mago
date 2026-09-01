<?php

declare(strict_types=1);

namespace Examples\ValueObject;

/**
 * A value object may hold a value object, and the rule skips it by the *enclosing* class's resolved name.
 * Metadata lowercases many names, so this is the case that says the guard reads a name that still has its
 * case: without it the port reports here and PHPStan does not.
 */
final class Invoice
{
    public function __construct(private readonly Money $total) {}
}
