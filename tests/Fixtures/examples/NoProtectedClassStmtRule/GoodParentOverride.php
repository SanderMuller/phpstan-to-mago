<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/** Overriding a protected parent method cannot widen it, so the rule skips one the parent declares. */
final class ConcreteWidget extends AbstractWidget
{
    protected function describe(): string
    {
        return 'concrete';
    }
}
