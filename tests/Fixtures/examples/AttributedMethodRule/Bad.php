<?php

declare(strict_types=1);

namespace Examples\Attributes;

final class Plain
{
    /** No attribute, so the rule reports. */
    public function bare(): void {}
}
