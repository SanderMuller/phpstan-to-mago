<?php

declare(strict_types=1);

namespace Examples\Ini;

/**
 * Four of the five ini functions, each reading an option deprecated at or below the analysed version.
 *
 * `enable_dl` carries threshold 0 and is reported whatever the version; `track_errors` is 70200,
 * `assert.active` 80300 and `session.sid_length` 80400. The message names the function as the codebase
 * declares it, which is what makes the port's resolved name and the original's reflected name one string.
 *
 * The version comparison itself is not controlled here: every threshold in the table is at or below the PHP
 * this suite runs, so no option in it can be silent for being too new. That axis is measured separately —
 * see VERIFICATION.md, where moving only the analysed version takes both engines from three findings to
 * four on the same file.
 */
function readsDeprecatedOptions(): string
{
    $first = ini_get('track_errors');
    ini_set('assert.active', '1');
    $later = ini_get('session.sid_length');
    $explicit = \ini_get('mbstring.func_overload');

    return $first . $later . $explicit . get_cfg_var('enable_dl');
}
