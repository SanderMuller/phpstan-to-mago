<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use Closure;

/**
 * Findings already computed this process, so four assertions about one rule cost one run of each analyser.
 *
 * The gate asks four questions per rule -- the port reports the bad example, stays silent on the good one,
 * the original reports the bad one, and the two agree -- and each was rebuilding the sandbox and running
 * both PHPStan and mago again. One of each answers all four, and the redundancy made that class 90.6% of a
 * 365-second suite.
 *
 * Keyed by a fingerprint of the rule's own source, every example beside it, and the target, so an edit to
 * any of them misses the store. That is what the per-call rebuild was protecting: mago has no result cache,
 * so a sandbox outliving a rule edit is the one thing that could make a dead rule look alive. Only a repeat
 * of the identical question is shared.
 *
 * Its own class because {@see FiresGate} is `final readonly` and this state is deliberately mutable.
 */
final class GateFindings
{
    /** @var array<string, array<string, list<string>>> */
    private static array $answers = [];

    /**
     * @param Closure(): array<string, list<string>> $compute
     *
     * @return array<string, list<string>>
     */
    public static function remember(string $key, Closure $compute): array
    {
        return self::$answers[$key] ??= $compute();
    }

    /** Cleared between targets in tests, where the same rule is asked about under a different one. */
    public static function forget(): void
    {
        self::$answers = [];
    }
}
