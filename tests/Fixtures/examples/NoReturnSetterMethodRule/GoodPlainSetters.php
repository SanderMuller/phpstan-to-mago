<?php

declare(strict_types=1);

namespace Examples\Setters;

/**
 * A setter that sets, and the four shapes that look like a return and are not.
 *
 * Each is a control for one step of the port, and each stays silent in both engines:
 *
 * - `setBare()` writes `return;` with no value. The visitor counts only a `Return_` whose `expr` is non-null,
 *   and mago says the same thing by giving the node no `Expression` child — drop that check and this reports.
 * - `setViaClosure()` returns 1 from a closure. The traverser answers `DONT_TRAVERSE_CURRENT_AND_CHILDREN`
 *   for a `Closure`, so the setter itself returns nothing — drop the stop and this reports.
 * - `setViaArrow()` is the same shape one step out. An arrow function's body is an expression rather than a
 *   `return`, in php-parser and in mago both, so nothing here should ever have counted.
 * - `setDelegating()` yields *from* somewhere. php-parser's `Yield_` does not cover `yield from`, which is a
 *   class of its own, while mago hangs `YieldFrom` under the same `Yield` wrapper as `YieldValue` — so
 *   matching the wrapper reports here and matching the two leaves is silent. It is the row that separates
 *   them.
 *
 * `setUp()` is the rule's own exemption, and `plainMethod()` fails the `#^set[A-Z]#` pattern.
 */
final class PlainSetters
{
    private string $name = '';

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setBare(string $name): void
    {
        $this->name = $name;

        return;
    }

    public function setViaClosure(string $name): void
    {
        $this->name = $name;

        $callback = function (): int {
            return 1;
        };

        $callback();
    }

    public function setViaArrow(string $name): void
    {
        $this->name = $name;

        $callback = fn (): int => 1;

        $callback();
    }

    /** @return iterable<string> */
    public function setDelegating(): iterable
    {
        yield from ['a', 'b'];
    }

    public function setUp(): int
    {
        return 1;
    }

    public function plainMethod(): int
    {
        return 1;
    }
}
