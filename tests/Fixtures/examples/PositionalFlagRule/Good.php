<?php

declare(strict_types=1);

namespace Examples\Flags;

use Vendor\Widget;

/**
 * One example per guard, so that dropping any one of them makes the pair fail rather than pass quietly.
 *
 * Checked by mutation, not by reading: removing the parameter guard reports lines 31 and 33, and removing the
 * first-party guard reports line 35.
 *
 * One guard of the original cannot be exercised from here, and no example could. `lastBareFlagIndex()` sweeps
 * every argument for a named or spread one after it has already checked the last argument for both. PHP
 * forbids a positional argument after a named or unpacked one — "Cannot use positional argument after argument
 * unpacking" — so whenever an earlier argument is named or spread, the last one is too, and the check on the
 * last argument has already bailed. The sweep is defensive code in valid PHP, so removing it from the port
 * leaves this pair green.
 */
final class Behaved
{
    public function go(array $rest): void
    {
        // Named, which is the whole point of the rule.
        new Sender('team', urgent: true);
        // Spread, so the argument position says nothing about the parameter position.
        new Sender(...$rest);
        // Two spreads, the last of them holding what would otherwise be a bare flag.
        new Sender(...$rest, ...['urgent' => true]);
        // Not a bare bool or null.
        new Sender('team', 'true');
        // No arguments at all.
        new Plain();
        // The flag lands on a variadic parameter.
        new Collector('team', true);
        // One argument more than the constructor declares.
        new Plain(true);
        // A constructor declared outside the first-party namespaces.
        new Widget(true);
    }
}

final class Plain
{
    public function __construct() {}
}

final class Collector
{
    public function __construct(public string $to, bool ...$flags) {}
}
