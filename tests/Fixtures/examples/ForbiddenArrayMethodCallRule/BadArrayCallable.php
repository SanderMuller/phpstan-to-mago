<?php

declare(strict_types=1);

namespace Examples\ArrayCallable;

final class BadArrayCallable
{
    public function handle(): void {}

    /** `[$this, 'handle']` names a method that exists, which is what the rule forbids. */
    public function callable(): array
    {
        return [$this, 'handle'];
    }
}
