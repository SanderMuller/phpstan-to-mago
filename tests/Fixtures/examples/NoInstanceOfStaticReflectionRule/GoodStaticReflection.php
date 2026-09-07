<?php

declare(strict_types=1);

namespace Examples\StaticReflection;

use PhpParser\Node;

/**
 * The exemption, the allow-list, and the two kinds the union guard has to decline.
 *
 * - `selfCheck()` is the rule's own skip, and the one keyword it names. `BadStaticReflection::lateStaticBinding()`
 *   is the row beside it: both are `Keyword` in mago's tree, so folding them together goes quiet on a case the
 *   original reports.
 * - `allowedByPrefix()` and `allowedByIsA()` name a php-parser class, which Rector's build autoloads, through
 *   each of the resolver's two branches.
 * - `arithmetic()` is a `Binary` like an `instanceof` is — mago gives them the same node kind — so it is the
 *   control for the operator test. It controls the *pair* of them, which is not what it was written to do:
 *   the emitted guard asks `Support::isInstanceof()` and the resolver asks the same question again, so
 *   breaking either one alone leaves this row passing and only breaking both reports on `+`. Measured, after
 *   a single mutation of each was expected to fail and did not.
 * - `plainCall()` and `propertyRead()` are two more kinds this hook registers and the rule reads nothing of.
 */
final class GoodStaticReflection
{
    public string $label = '';

    public function selfCheck(object $value): bool
    {
        return $value instanceof self;
    }

    public function allowedByPrefix(object $value): bool
    {
        return $value instanceof Node;
    }

    public function allowedByIsA(object $value): bool
    {
        return is_a($value, Node::class);
    }

    public function arithmetic(int $left, int $right): int
    {
        return $left + $right;
    }

    public function plainCall(): string
    {
        return $this->describe();
    }

    public function propertyRead(): string
    {
        return $this->label;
    }

    private function describe(): string
    {
        return 'nothing to report';
    }
}
