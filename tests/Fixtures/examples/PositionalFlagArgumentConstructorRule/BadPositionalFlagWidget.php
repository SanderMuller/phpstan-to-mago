<?php

declare(strict_types=1);

namespace App\Widgets;

/**
 * The declaring class has to be first-party for the rule to ask about it at all.
 *
 * `App` is one of the namespaces the package's own neon configures, and the gate registers the original rule
 * with those same values, so both sides are asking the same question.
 */
final class BadPositionalFlagWidget
{
    public function __construct(public bool $enabled) {}

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public static function make(): self
    {
        return new self(true);
    }
}
