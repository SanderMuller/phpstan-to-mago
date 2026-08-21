<?php

declare(strict_types=1);

namespace App\Reporting;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Every way of naming a class that is *not* an alias, so a port that lost a guard reports here.
 *
 * The imported facade is the important one: it is how the real application uses facades throughout, which is
 * why that project produced no findings on either side.
 */
final class GoodImportedFacades
{
    public function readThroughRealNames(): void
    {
        // Imported by its real name, so this resolves to four segments rather than to an alias.
        Cache::get('key');

        // Fully qualified in place: more than one segment, which the rule skips before asking anything.
        Route::getRoutes();

        // Resolved inside the current namespace, so not an alias use at all.
        Helper::help();

        // In Laravel's alias map, and *not* a facade: `Arr`, `Str`, `Number`, `Date`, `Js` and `Uri` alias
        // plain helper classes. Without the subclass test the port reports these, and `\Arr::` is common
        // enough that the pair has to hold it — mutating that test away changed nothing until this line
        // existed.
        \Arr::get(['a' => 1], 'a');

        // Names that resolve against the enclosing class.
        self::help();
        self::help();
    }

    public static function help(): void {}
}

final class Helper
{
    public static function help(): void {}
}
