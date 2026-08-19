<?php

declare(strict_types=1);

namespace Examples\Config;

/**
 * One closure per way the detector says no.
 *
 * Which of them are load-bearing was measured, not assumed. Weakening the `instanceof Name` test to mere
 * hint-presence reports the **union** closure, because a union hint resolves to its first name and then passes the
 * class comparison. Dropping the class comparison reports the **other class**. The builtin, nullable and untyped
 * closures are rejected by the class comparison whichever way the name test reads, so they document the shape
 * rather than guard it.
 */
final class Behaved
{
    /** @return list<callable> */
    public function register(): array
    {
        return [
            // Two parameters, so not the single-parameter shape.
            static function (Configurator $configurator, int $flag): void {},
            // No parameters at all.
            static function (): void {},
            // A parameter with no written type.
            static function ($configurator): void {},
            // A builtin type: an `Identifier` to php-parser, not a `Name`. Rejected by the class comparison.
            static function (int $configurator): void {},
            // Nullable, which php-parser gives as a `NullableType`. Also rejected by the class comparison.
            static function (?Configurator $configurator): void {},
            // A union — the one the name test itself has to reject, since it resolves to its first name.
            static function (Configurator|int $configurator): void {},
            // A class, but not the one this rule asks for: what the class comparison is there for.
            static function (Registrar $configurator): void {},
        ];
    }
}
