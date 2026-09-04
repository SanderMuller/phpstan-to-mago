<?php

declare(strict_types=1);

namespace Divergence\TruthyNarrowedCallable;

final class Subject
{
    /**
     * A docblock-only `callable|null`, narrowed by truthiness and called with a spread.
     *
     * The shape at `Illuminate/Support/Testing/Fakes/QueueFake.php` — recorded once at `:214` and once at
     * `:167`, the drift that motivated pinning cases. There is no native parameter type; the only statement
     * of what `$callback` holds is the docblock, and the narrowing is `$callback ? ... : ...` rather than an
     * `instanceof`.
     *
     * @param callable|null $callback
     */
    public function truthy($callback): bool
    {
        return $callback ? $callback(...func_get_args()) : true;
    }

    /**
     * The control, and it took two attempts to write one.
     *
     * A `callable` parameter is exempt on both sides, so calling one proves nothing — the first version of
     * this method recorded silence on both engines and read as agreement. The rule reports a dynamic name
     * whose subject is *not* known-callable, so the control has to be a plain `string`.
     */
    public function plain(string $other): void
    {
        $other();
    }
}
