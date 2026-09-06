<?php

declare(strict_types=1);

namespace Examples\Ini;

/**
 * Three ways to be silent, one per guard the rule opens with.
 *
 * An option the table does not name; a function outside the five the rule watches, so the name test is what
 * declines it; and an argument that is not a literal, which resolves to no constant string for the loop to
 * look up.
 */
function readsCurrentOptions(string $name): string
{
    $limit = ini_get('memory_limit');
    $lowered = strtolower('track_errors');

    return $limit . $lowered . ini_get($name);
}
