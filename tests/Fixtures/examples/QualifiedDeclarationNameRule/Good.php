<?php

declare(strict_types=1);

namespace Examples\Naming;

/**
 * Suffixed, and the suffix is mixed case. A name read out of metadata would arrive lowercased and
 * `str_ends_with(.., 'FormType')` would miss it, so this class would be reported.
 */
final class ContactFormType
{
    /**
     * An anonymous class. PHPStan visits it with a `Class_` rule and leaves `namespacedName` null, so the
     * original returns early; the port never receives it, because Mago gives it its own node kind. Both stay
     * silent, by different routes — and folding the guard is only sound while that holds.
     */
    public function make(): object
    {
        return new class {};
    }
}
