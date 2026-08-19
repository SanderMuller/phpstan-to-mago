<?php

declare(strict_types=1);

namespace Examples\Receivers;

final class Widget
{
    public function toggle(bool $enabled): void {}

    public function reset(): void {}
}

final class Caller
{
    private ?Widget $maybe = null;

    public function __construct(private Widget $sure) {}

    public function go(): void
    {
        $this->sure->toggle(true);
        // A nullable receiver reached with a plain arrow. Its inferred type carries a null atomic beside the
        // object one, so this line is what proves the null-dropping variant runs: the strict helper answers
        // "not one class" here and the rule goes silent.
        $this->maybe->toggle(true);
    }
}
