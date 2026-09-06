<?php

declare(strict_types=1);

namespace Examples\Ini;

/**
 * Four ways to be silent, one per guard the rule opens with and one for how a call resolves.
 *
 * An option the table does not name; a function outside the five the rule watches, so the name test is what
 * declines it; and an argument that is not a literal, which resolves to no constant string for the loop to
 * look up.
 *
 * The fourth is `ini_get()` called where this namespace declares its own. PHP resolves that to
 * `Examples\Ini\ini_get()`, not to the global function, so neither engine reports it — and a port reading
 * the written text rather than the resolved name would answer the global one and report. Mago resolves the
 * name to the namespaced candidate whether or not it exists, which is why the fallback has to be applied
 * after the lookup rather than before it.
 */
function ini_get(string $option): string
{
    return $option;
}

function readsCurrentOptions(string $name): string
{
    $limit = ini_get('memory_limit');
    $lowered = strtolower('track_errors');
    $shadowed = ini_get('track_errors');

    return $limit . $lowered . $shadowed . ini_get($name);
}
