<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/** One finding per protected member, and the rule reports the member's own line rather than the class's. */
final class Widget
{
    protected const string LABEL = 'widget';

    protected int $uses = 0;

    protected function describe(): string
    {
        return self::LABEL;
    }
}
