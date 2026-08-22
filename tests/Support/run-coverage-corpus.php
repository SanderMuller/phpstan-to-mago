<?php

declare(strict_types=1);

/**
 * The parameter aggregate, counted two ways over a consumer project.
 *
 *   php tests/Support/run-coverage-corpus.php <consumer-root> [--paths=a,b] [--exclude=a,b] [--sandbox=DIR]
 *
 * Prints the corpus size, the real rule's total, the port's, and the delta. This is the instrument behind the
 * numbers `Vocabulary::unverifiedAggregate('parameters')` quotes, and it is in the repository so that those
 * numbers can be repeated rather than taken on trust.
 *
 * Paths and exclusions come from the consumer's own configuration, so neither tool is measured on a corpus the
 * other never saw. Writes nothing into the consumer.
 */

use Nette\Neon\Neon;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageCorpus;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$consumer = null;
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-coverage-corpus';
$requested = null;
$extraExcludes = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--paths=')) {
        $requested = explode(',', substr($argument, 8));
    } elseif (str_starts_with($argument, '--exclude=')) {
        // Leave-one-out is the only sound way to bisect a delta that needs several directories together: a
        // trait and the classes using it are counted once per user, so measuring either alone measures
        // something else. `--exclude` takes a directory out of an otherwise whole run.
        $extraExcludes = explode(',', substr($argument, 10));
    } elseif (str_starts_with($argument, '--sandbox=')) {
        $sandbox = substr($argument, 10);
    } else {
        $consumer = rtrim($argument, '/');
    }
}

if ($consumer === null || ! is_dir($consumer . '/vendor')) {
    fwrite(STDERR, "Give a consumer project root that has its dependencies installed.\n");

    exit(1);
}

$resolved = realpath($consumer);
$consumer = $resolved === false ? $consumer : $resolved;

$configurationFile = CorpusDifferential::configurationOf($consumer);
if (! is_file($configurationFile)) {
    fwrite(STDERR, "The consumer has neither phpstan.neon nor phpstan.neon.dist, so there is nothing to include.\n");

    exit(1);
}

/** @var array{parameters?: array{paths?: list<string>, excludePaths?: list<string>|array<string, list<string>>}} $configuration */
$configuration = (array) Neon::decode((string) file_get_contents($configurationFile));

$configured = $configuration['parameters']['paths'] ?? null;
$paths = $requested ?? $configured;
if ($paths === null) {
    fwrite(STDERR, "{$configurationFile} declares no paths; pass --paths=a,b,c.\n");

    exit(1);
}

$absolute = static fn (string $path): string => str_starts_with($path, '/') ? $path : $consumer . '/' . $path;

$paths = array_values(array_filter(array_map($absolute, $paths), file_exists(...)));
if ($paths === []) {
    fwrite(STDERR, "None of the configured paths exist.\n");

    exit(1);
}

// PHPStan applies its exclusions to the paths it was given, so a corpus that ignores them is not the corpus
// the original ran on. `excludePaths` is either a list or a map keyed `analyse`/`analyseAndScan`.
$excludes = [];
foreach ($configuration['parameters']['excludePaths'] ?? [] as $entry) {
    foreach (is_array($entry) ? $entry : [$entry] as $path) {
        $excludes[] = $absolute($path);
    }
}

foreach ($extraExcludes as $path) {
    $excludes[] = $absolute($path);
}

// Everything the consumer configures for analysis is resolvable, whether or not this run analyses it. A
// narrower `--paths=` then still asks both tools about the same universe of symbols, which is what makes
// bisecting a delta by directory mean anything.
$resolvable = array_values(array_filter(array_map($absolute, $configured ?? $paths), file_exists(...)));

$corpus = new CoverageCorpus(
    repositoryRoot: dirname(__DIR__, 2),
    consumerRoot: $consumer,
    configurationFile: $configurationFile,
    paths: $paths,
    resolvable: $resolvable,
    excludes: array_values(array_unique($excludes)),
    sandbox: $sandbox,
);

$totals = $corpus->totals();

printf("%s  (%d files)\n", $consumer, $corpus->files());
printf("  original: %d parameters\n", $totals['original']);
printf("  port:     %d parameters\n", $totals['port']);
printf("  delta:    %+d\n", $totals['port'] - $totals['original']);
