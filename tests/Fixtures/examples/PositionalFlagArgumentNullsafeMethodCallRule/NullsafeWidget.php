<?php

declare(strict_types=1);

namespace App\Widgets;

/**
 * The receiver whose method the rule asks about, in this rule's own example directory.
 *
 * The gate copies one rule's examples and nothing else, so a class shared with a sibling rule's directory is
 * simply undefined here — the receiver's type then does not resolve, both tools stay silent, and the pair
 * proves nothing. That is how this file came to exist.
 */
final class NullsafeWidget
{
    public function setEnabled(bool $enabled): void {}

    public function rename(string $label): void {}
}
