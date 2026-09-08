<?php

declare(strict_types=1);

namespace Examples\Comparisons;

/**
 * The strict operators, and one comparison that is neither.
 *
 * `===` and `!==` are the controls a prefix or substring match would fail: the emitted guard compares the
 * operator's text exactly, and `==` is a prefix of `===` while `!=` is a prefix of `!==`. This hook registers
 * every `Binary` node, so `<` is here too — a kind the plugin sees and the rule declines.
 */
final class GoodStrictComparison
{
    public function identical(int $left, int $right): bool
    {
        return $left === $right;
    }

    public function notIdentical(int $left, int $right): bool
    {
        return $left !== $right;
    }

    public function smaller(int $left, int $right): bool
    {
        return $left < $right;
    }
}
