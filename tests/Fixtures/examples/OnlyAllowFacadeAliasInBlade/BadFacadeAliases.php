<?php

declare(strict_types=1);

namespace App\Reporting;

use Route;

/**
 * The corpus this rule could not be proven on had to be built.
 *
 * `measurement-static-facade-map.md` audited 1899 files of a real Laravel application and found six distinct
 * bare-name static references, none of them a facade alias — that project imports its facades. So the
 * differential can only show the port adds no false positive there; the positive path is exercised here,
 * against real PHPStan with the alias loader registered the way larastan registers it.
 *
 * Both shapes that reach the rule at all, measured with php-parser's own `NameResolver`: a leading-backslash
 * reference, and a `use` of the global alias. An *unimported* `Cache::get()` here would resolve to
 * `App\Reporting\Cache` and the rule would skip it, which is why neither example uses that form.
 */
final class BadFacadeAliases
{
    public function readThroughAliases(): void
    {
        // Leading backslash: resolves to the one-segment name `Cache`.
        \Cache::get('key');

        // Imported global alias: resolves to the one-segment name `Route`. A second alias, so a port that
        // hard-wired one name is still caught.
        Route::getRoutes();
    }
}
