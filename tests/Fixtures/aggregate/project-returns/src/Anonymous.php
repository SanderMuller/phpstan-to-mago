<?php

declare(strict_types=1);

namespace Aggregate\Returns;

use Closure;

/**
 * A closure with no return type, which neither engine counts.
 *
 * Written expecting the opposite — the collector's node type is `FunctionLike`, so a closure looked like it
 * would count — and the run said 4 possible rather than 5, with PHPStan agreeing. The aggregate walks the
 * codebase's *method* list, so a closure never reaches it.
 *
 * Kept because that is worth pinning, and because it retires a claim: the anchor read
 * `nameLocation ?? location` for a nullable name, and the case named for it was a closure. A methods loop
 * never sees one, and a method always has a name, so the fallback was unreachable rather than defensive.
 */
final class Anonymous
{
    public function make(): Closure
    {
        return static function () {
            return 1;
        };
    }
}
