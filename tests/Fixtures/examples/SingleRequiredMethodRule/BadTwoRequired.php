<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Contracts\Service\Attribute\Required;

final class BadTwoRequired
{
    /**
     * Two `#[Required]` methods, which is what the rule counts.
     *
     * The count is the point: this rule reads a number carried across the loop that walks the class's
     * methods, tests it against a threshold, and puts it in the message. A port that folded the counter to
     * its initial value would report `Found 0` here, or not report at all.
     */
    #[Required]
    public function setFirst(object $thing): void {}

    #[Required]
    public function setSecond(object $thing): void {}
}
