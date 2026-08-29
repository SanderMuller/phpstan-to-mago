<?php

declare(strict_types=1);

namespace Examples\Billing;

/**
 * An interface outside a "Contract" or "Contracts" namespace.
 *
 * The namespace is what the rule reads, not the file or the interface name, so `Billing` is the whole of
 * the violation here.
 */
interface BadPayment
{
    public function charge(): void;
}
