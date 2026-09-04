<?php

declare(strict_types=1);

namespace CallableRefinement;

use Closure;

/**
 * Six starting types under one `is_callable()` guard, read at the same node position in each.
 *
 * The subject of `ReadsTheCallableStringRefinementTest`. Varying the *starting* type rather than the read
 * position is what makes the flag's behaviour visible: mago narrows
 * a `string` to a `callable-string`, which is a `ScalarType` of kind `String` carrying `callable: true` on its
 * refinement, and it narrows a `string|int` by dropping the `int` while leaving the string atomic in place.
 *
 * `$u` is assigned and never read on purpose: the assignment exists to give the hook a node to fire on, and
 * reading it back would put a second inference between the guard and the measurement.
 */
final class Narrow
{
    public function fromMixed(mixed $a): void
    {
        if (is_callable($a)) {
            $u = $a;
        }
    }

    public function fromString(string $b): void
    {
        if (is_callable($b)) {
            $u = $b;
        }
    }

    public function fromStringOrClosure(string|Closure $c): void
    {
        if (is_callable($c)) {
            $u = $c;
        }
    }

    public function fromStringOrInt(string|int $d): void
    {
        if (is_callable($d)) {
            $u = $d;
        }
    }

    public function fromCallableOrString(callable|string $e): void
    {
        if (is_callable($e)) {
            $u = $e;
        }
    }

    /** The control: the same shape under a sibling assertion, where the flag must stay false. */
    public function fromMixedUnderIsString(mixed $f): void
    {
        if (is_string($f)) {
            $u = $f;
        }
    }
}
