<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/** An abstract class, which the rule skips outright — protected is how a base class offers a hook. */
abstract class AbstractWidget
{
    protected const string LABEL = 'abstract';

    protected int $uses = 0;

    protected function describe(): string
    {
        return self::LABEL;
    }
}
