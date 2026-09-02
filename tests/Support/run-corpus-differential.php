<?php

declare(strict_types=1);

/**
 * The corpus differential, run against a consumer project.
 *
 *   php tests/Support/run-corpus-differential.php <consumer-root> [--threads=N] [--sandbox=DIR]
 *       [--paths=a,b] [--packages=vendor/one,vendor/two] [--parameter=name=true]
 *       [--extension-host=/path/to/plugin.php]
 *
 * Prints the emission counts, the corpus size, and per identifier the agree / only-original / only-port
 * split. Writes nothing into the consumer, and nothing into this repository: the sandbox holds the
 * generated plugins, the worker and both configurations.
 */

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$consumer = null;
$threads = null;
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-differential';
$paths = null;
// Which rule packages to transpile out of the consumer's own vendor. The default is the four this
// repository installs; a consumer with others — a Symfony application carrying `phpstan-symfony`,
// `phpstan-phpunit` and `phpstan-strict-rules` — names them instead, because a package that is not read
// contributes a silent zero rather than a measurement.
$packages = null;
// A container parameter forced to a value on *both* sides, for asking what a corpus would report at a
// configuration it does not run. Two corpora that differ in a flag cannot answer whether that flag or
// something else drives a difference between them; one corpus run twice can.
/** @var array<string, bool> $overrides */
$overrides = [];
// An extra analyzer extension on the *mago* side, for asking what the difference looks like when the two
// engines carry comparable plugins. PHPStan reaches a corpus through larastan or phpstan-symfony; mago
// reaches it through nothing, and a difference measured across that gap describes the gap rather than the
// port.
$extensionHosts = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--paths=')) {
        $paths = explode(',', substr($argument, 8));
    } elseif (str_starts_with($argument, '--threads=')) {
        $threads = (int) substr($argument, 10);
    } elseif (str_starts_with($argument, '--sandbox=')) {
        $sandbox = substr($argument, 10);
    } elseif (str_starts_with($argument, '--packages=')) {
        $packages = explode(',', substr($argument, 11));
    } elseif (str_starts_with($argument, '--extension-host=')) {
        $extensionHosts[] = substr($argument, 17);
    } elseif (str_starts_with($argument, '--parameter=')) {
        [$name, $value] = array_pad(explode('=', substr($argument, 12), 2), 2, 'true');
        $overrides[$name] = $value === 'true';
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

// The consumer's own analysed paths, so neither tool is measured on a corpus the other never saw. Read from
// its configuration rather than assumed: a corpus one tool did not see is the published-mistake generator
// the procedure warns about.
$configurationFile = CorpusDifferential::configurationOf($consumer);
if (! is_file($configurationFile)) {
    fwrite(STDERR, "The consumer has neither phpstan.neon nor phpstan.neon.dist, so there is nothing to include.\n");

    exit(1);
}

/** @var array{parameters?: array{paths?: list<string>, excludePaths?: list<string>|array<string, list<string>>}} $configuration */
$configuration = (array) Neon::decode((string) file_get_contents($configurationFile));

if ($paths === null) {
    $paths = $configuration['parameters']['paths'] ?? null;
    if ($paths === null) {
        fwrite(STDERR, "{$configurationFile} declares no paths; pass --paths=a,b,c.\n");

        exit(1);
    }
}

// A path may already be absolute: a control run points both tools at a fixture tree outside the consumer.
$paths = array_values(array_filter(
    $paths,
    static fn (string $path): bool => file_exists(str_starts_with($path, '/') ? $path : $consumer . '/' . $path),
));

// Its exclusions too. PHPStan applies these to the paths it was given, so a corpus that ignores them is not
// the corpus the original ran on. `excludePaths` is either a list or a map keyed `analyse`/`analyseAndScan`.
//
// An entry may carry PHPStan's optional marker — `config/reference.php (?)` for a path that only exists after
// a build step — and neon parses that as an `Entity` rather than a string. `symfony/demo` writes one, and
// without unwrapping it the run died on a TypeError inside the exclusion test. The marker itself says nothing
// about the corpus, so the path is what is kept.
$excludePaths = $configuration['parameters']['excludePaths'] ?? [];
$excludes = [];
foreach ($excludePaths as $entry) {
    foreach (is_array($entry) ? $entry : [$entry] as $path) {
        $path = $path instanceof Entity ? $path->value : $path;
        if (is_string($path)) {
            $excludes[] = $path;
        }
    }
}

if (! is_dir($sandbox)) {
    mkdir($sandbox, 0o777, true);
}

$differential = new CorpusDifferential(
    repositoryRoot: dirname(__DIR__, 2),
    consumerRoot: $consumer,
    sandbox: $sandbox,
    packages: $packages ?? [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
    ],
    paths: $paths,
    excludes: $excludes,
    overrides: $overrides,
    extensionHosts: $extensionHosts,
);

$counts = $differential->emit();
$files = $differential->corpusFiles();
$differential->writeMagoConfig();
$differential->writePhpstanConfig();

echo "emitted: {$counts['emitted']}, refused: {$counts['refused']} (target: php)\n";
echo 'corpus: ', count($files), " files\n";
echo 'identifiers under test: ', count($differential->identifiers()), "\n";

if ($differential->parameterFailure !== null) {
    echo 'WARNING: ', $differential->parameterFailure, "\n";
    echo "  The plugins run at package defaults rather than this consumer's values, so a flag-sensitive\n";
    echo "  rule is measured against a configuration the consumer does not run.\n";
}

$absent = $differential->packagesNotInstalled();
if ($absent !== []) {
    echo 'rule packages this consumer does not install, so nothing from them was measured: ',
    implode(', ', $absent), "\n";
}

$unregistered = $differential->notRegisteredHere();
if ($unregistered !== []) {
    echo 'not registered by this harness (constructor takes a configured value, so the original runs only if ',
    "the consumer's own config registers it): ", implode(', ', $unregistered), "\n";
}

echo "\n";

$port = $differential->magoFindings($threads);
$original = $differential->phpstanFindings();

$agree = 0;
$onlyOriginal = 0;
$onlyPort = 0;
$suppressed = 0;
$silent = [];
foreach ($differential->compare($original, $port) as $identifier => $result) {
    $rules = implode(', ', $differential->identifiers()[$identifier]);
    $agree += count($result['agree']);
    $onlyOriginal += count($result['onlyOriginal']);
    $onlyPort += count($result['onlyPort']);
    $suppressed += count($result['suppressed']);

    // An identifier neither tool reported anything for proves nothing about this corpus, and its `0 0 0` row
    // reads exactly like a clean agreement. `.ai/guidelines/verification.md` names this: two tools reporting
    // nothing is equally consistent with "the code is clean" and "the second one never looked". Collected so
    // the summary can say how much of the row count is coverage and how much is silence.
    if ($result['agree'] === [] && $result['onlyOriginal'] === [] && $result['onlyPort'] === [] && $result['suppressed'] === []) {
        $silent[] = $identifier;
    }

    printf(
        "%-46s agree %3d  only-original %3d  only-port %3d  suppressed %3d   %s\n",
        $identifier,
        count($result['agree']),
        count($result['onlyOriginal']),
        count($result['onlyPort']),
        count($result['suppressed']),
        $rules,
    );

    foreach ($result['onlyOriginal'] as $site) {
        echo "    only-original  {$site}\n";
    }

    foreach ($result['onlyPort'] as $site) {
        echo "    only-port      {$site}\n";
    }

    foreach ($result['suppressed'] as $site) {
        echo "    suppressed     {$site}\n";
    }

    foreach ($result['differingMessages'] as $site) {
        echo "    same site, different message  {$site}\n";
    }
}

echo "\ntotal: agree {$agree}, only-original {$onlyOriginal}, only-port {$onlyPort}",
$suppressed === 0 ? "\n" : ", suppressed {$suppressed} (the original finds these too, and the consumer silenced them with @phpstan-ignore)\n";

$exercised = count($differential->identifiers()) - count($silent);
printf(
    "exercised: %d of %d identifiers; %d reported nothing on either side, so this corpus says nothing about them\n",
    $exercised,
    count($differential->identifiers()),
    count($silent),
);

if ($silent !== []) {
    // Named, not just counted. A corpus is chosen, and which rules it cannot reach is the first thing that
    // tells you to choose another one — every Laravel-shaped rule is silent on a library, and reading the
    // total alone would never say so.
    sort($silent);
    echo '  ', implode("\n  ", array_map(
        static fn (string $identifier): string => $identifier,
        array_slice($silent, 0, 40),
    )), count($silent) > 40 ? "\n  ..." : '', "\n";
}

exit($onlyOriginal === 0 && $onlyPort === 0 ? 0 : 1);
