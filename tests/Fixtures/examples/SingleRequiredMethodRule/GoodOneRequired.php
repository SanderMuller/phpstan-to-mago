<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Contracts\Service\Attribute\Required;

final class GoodOneRequired
{
    /** One `#[Required]` method, which is what the rule asks for. */
    #[Required]
    public function setOnly(object $thing): void {}

    /**
     * A method the loop walks and does not count, so the threshold is reached by the counter rather than by
     * the number of methods.
     */
    public function ordinary(): void {}
}
