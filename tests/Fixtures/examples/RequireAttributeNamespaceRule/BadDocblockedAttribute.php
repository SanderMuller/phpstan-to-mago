<?php

declare(strict_types=1);

namespace Examples\Marking;

use Attribute;

/**
 * The shape a real consumer writes: a docblock, then an attribute *with arguments*, then a `final readonly`
 * class. All three sit between the class keyword and the top of the declaration, and the finding's line is
 * the one thing a reader compares first.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Described
{
    public function __construct(public string $text) {}
}
