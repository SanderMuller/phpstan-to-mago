<?php declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorPest\Set\PestSetList;

return RectorConfig::configure()
    ->withCache(
        cacheDirectory: './.cache/rector',
        cacheClass: FileCacheStorage::class,
    )
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Reviewed transpiler output, and rule fixtures shaped to be transpiled rather than to be tidy.
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withAttributesSets()
    ->withImportNames()
    ->withFluentCallNewLine()
    ->withParallel(300, 15, 15)
    ->withMemoryLimit('3G')
    ->withPhpSets(php83: true)
    ->withSets(class_exists(PestSetList::class) ? [
        PestSetList::PEST_CODE_QUALITY,
        PestSetList::PEST_CHAIN,
    ] : [])
    ->withSkip([
        NullToStrictStringFuncCallArgRector::class,
        AddArrowFunctionReturnTypeRector::class,
        ExplicitBoolCompareRector::class,
        InlineArrayReturnAssignRector::class,
        PrivatizeFinalClassMethodRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
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
    ]);
