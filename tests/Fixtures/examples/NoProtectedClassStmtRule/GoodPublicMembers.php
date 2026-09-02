<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/** Public members, which the rule asks for. */
final class PublicWidget
{
    public const string LABEL = 'widget';

    public int $uses = 0;

    public function describe(): string
    {
        return self::LABEL;
    }

    private function counted(): int
    {
        return $this->uses;
    }
}
