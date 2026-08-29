<?php

declare(strict_types=1);

namespace Examples\Billing\Contract;

/**
 * The same interface, in a namespace the pattern accepts.
 *
 * `#\bContracts?\b#` matches the singular and the plural, and it matches a segment anywhere in the
 * namespace rather than only the last one.
 */
interface GoodPayment
{
    public function charge(): void;
}
