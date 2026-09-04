<?php

declare(strict_types=1);

namespace Divergence\CallableStringRefinement;

use Closure;

final class Subject
{
    /**
     * A dynamic call whose subject `is_callable()` has narrowed.
     *
     * `NoDynamicNameRule` exempts a callable, so neither engine should report here. The port answered
     * otherwise until `Types::isCallableAtomic()` learned to read the `callable` flag: mago narrows a string
     * to a `callable-string`, which is a `ScalarType` carrying `callable: true` on its refinement, and the
     * predicate read only `literalValue` off that refinement.
     */
    public function guarded(string|Closure $callback): void
    {
        if (is_callable($callback)) {
            $callback();
        }
    }

    /**
     * The control, and the reason this case is not agreement-on-nothing.
     *
     * The same call with no guard is a dynamic name both engines report, so a run where the case falls
     * silent for an unrelated reason still shows one finding here and fails the comparison rather than
     * passing as "agreed".
     */
    public function unguarded(string|Closure $other): void
    {
        $other();
    }
}
