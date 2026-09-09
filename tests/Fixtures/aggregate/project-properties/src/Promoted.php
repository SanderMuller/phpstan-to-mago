<?php declare(strict_types=1);

namespace Coverage;

/**
 * A constructor-promoted property, which the metric does not count at all — the second of the four
 * measured behaviours. If it were counted the totals below move, which is what makes this file
 * load-bearing rather than decoration.
 */
class Promoted
{
    public function __construct(public $promoted = null) {}
}
