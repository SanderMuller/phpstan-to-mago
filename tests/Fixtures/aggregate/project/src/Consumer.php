<?php

declare(strict_types=1);

namespace Cov;

final class Consumer
{
    use Shared;

    public function own($untyped, string $typed): void {}

    public function __construct($promoted) {}

    /**
     * A magic method. The collector skips one, so counting it here would inflate the total and the two tools
     * would disagree on the percentage.
     */
    public function __get($name)
    {
        return null;
    }

    /**
     * A variadic parameter has no single declaration site, and the collector skips it. Counting it would
     * inflate the total.
     */
    public function many($first, ...$rest): void {}
}
