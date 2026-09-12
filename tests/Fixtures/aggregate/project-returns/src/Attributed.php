<?php

declare(strict_types=1);

namespace Aggregate\Returns;

#[\Attribute]
final class Marker {}

/**
 * The file this fixture exists for.
 *
 * `ReturnTypeDeclarationCollector` reports `$node->getLine()` on the function-like, and php-parser's start
 * line for an attributed method is the attribute's — a line above `public function`. Anchoring on the
 * method's *name* instead is one line out, and only here: every other declaration in this fixture has
 * nothing above it, so the two anchors coincide and no total moves either way.
 *
 * Measured on `Illuminate\Database\Eloquent` before this file existed: 33 findings each way, every pair
 * exactly one line apart, and all 33 attributed.
 */
final class Attributed
{
    #[Marker]
    public function tagged()
    {
        return 1;
    }
}
