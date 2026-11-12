<?php declare(strict_types=1);

use Rector\Carbon\Rector\FuncCall\DateFuncCallToCarbonRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use SanderMuller\RectorPreset\Preset;

return Preset::package()
    ->withPhpSets(php83: true)
    // Reviewed transpiler output, and rule fixtures shaped to be transpiled rather than to be tidy.
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
    ])
    ->withSkip([
        // A test naming a fixture-package class does so as a string on purpose: those packages are fixture
        // *input* for the transpiler, excluded from analysis, so a `::class` constant on one is a symbol
        // PHPStan cannot resolve.
        StringClassNameToClassConstantRector::class,
        // The same reason one level up, and it matters more in `src/`: a corpus package is *data* in a
        // vocabulary table, not a dependency of the transpiler. Turning a key into `SomeTrait::class` would
        // add an import from a dev-only rule package into shipped code, so a consumer installing without dev
        // dependencies would see `src/` name a class it does not have — and the property that makes a new
        // corpus cheap to adopt is exactly that packages are named as data.
        UseClassKeywordForClassNameResolutionRector::class,
        // `nesbot/carbon` is in no `require` block here — it arrives transitively through testbench. Rewriting
        // `date()` to `Carbon::now()` in `src/` would ship an import against an undeclared dependency, and a
        // consumer installing without dev dependencies would fatal on it. `tests/` may use Carbon, because a
        // dev-only dependency is exactly what a test is allowed to reach for.
        DateFuncCallToCarbonRector::class => [
            __DIR__ . '/src',
        ],
    ]);
