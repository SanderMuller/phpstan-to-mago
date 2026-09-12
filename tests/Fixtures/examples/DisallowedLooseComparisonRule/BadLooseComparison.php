<?php

declare(strict_types=1);

namespace Examples\Comparisons;

/**
 * Both loose operators, which are the rule's two identifiers and its two branches.
 *
 * The message carries no operand types here, and that is the configured default rather than an omission:
 * `includeOperandTypesInErrorMessage` is wired to PHPStan's `%featureToggles.bleedingEdge%`, which
 * `conf/config.neon` declares as `false`. The emitted plugin carries the same flag with the same default and
 * the same ternary, so a consumer on bleeding edge gets the longer message from the other arm.
 */
final class BadLooseComparison
{
    public function equal(int $left, int $right): bool
    {
        return $left == $right;
    }

    public function notEqual(int $left, int $right): bool
    {
        return $left != $right;
    }
}
