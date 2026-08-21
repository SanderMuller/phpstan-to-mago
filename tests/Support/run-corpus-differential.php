<?php

declare(strict_types=1);

/**
 * The corpus differential, run against a consumer project.
 *
 *   php tests/Support/run-corpus-differential.php <consumer-root> [--threads=N] [--sandbox=DIR]
 *
 * Prints the emission counts, the corpus size, and per identifier the agree / only-original / only-port
 * split. Writes nothing into the consumer, and nothing into this repository: the sandbox holds the
 * generated plugins, the worker and both configurations.
 */

use Nette\Neon\Neon;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$consumer = null;
$threads = null;
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-differential';
$paths = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--paths=')) {
        $paths = explode(',', substr($argument, 8));
    } elseif (str_starts_with($argument, '--threads=')) {
        $threads = (int) substr($argument, 10);
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

// The consumer's own analysed paths, so neither tool is measured on a corpus the other never saw. Read from
// its configuration rather than assumed: a corpus one tool did not see is the published-mistake generator
// the procedure warns about.
/** @var array{parameters?: array{paths?: list<string>, excludePaths?: list<string>|array<string, list<string>>}} $configuration */
$configuration = (array) Neon::decode((string) file_get_contents($consumer . '/phpstan.neon'));

if ($paths === null) {
    $paths = $configuration['parameters']['paths'] ?? null;
    if ($paths === null) {
        fwrite(STDERR, "The consumer's phpstan.neon declares no paths; pass --paths=a,b,c.\n");

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
$excludePaths = $configuration['parameters']['excludePaths'] ?? [];
$excludes = [];
foreach ($excludePaths as $entry) {
    foreach (is_array($entry) ? $entry : [$entry] as $path) {
        $excludes[] = $path;
    }
}

if (! is_dir($sandbox)) {
    mkdir($sandbox, 0o777, true);
}

$differential = new CorpusDifferential(
    repositoryRoot: dirname(__DIR__, 2),
    consumerRoot: $consumer,
    sandbox: $sandbox,
    packages: [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
    ],
    paths: $paths,
    excludes: $excludes,
);

$counts = $differential->emit();
$files = $differential->corpusFiles();
$differential->writeMagoConfig();
$differential->writePhpstanConfig();

echo "emitted: {$counts['emitted']}, refused: {$counts['refused']} (target: php)\n";
echo 'corpus: ', count($files), " files\n";
echo 'identifiers under test: ', count($differential->identifiers()), "\n";

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
foreach ($differential->compare($original, $port) as $identifier => $result) {
    $rules = implode(', ', $differential->identifiers()[$identifier]);
    $agree += count($result['agree']);
    $onlyOriginal += count($result['onlyOriginal']);
    $onlyPort += count($result['onlyPort']);

    printf(
        "%-46s agree %3d  only-original %3d  only-port %3d   %s\n",
        $identifier,
        count($result['agree']),
        count($result['onlyOriginal']),
        count($result['onlyPort']),
        $rules,
    );

    foreach ($result['onlyOriginal'] as $site) {
        echo "    only-original  {$site}\n";
    }

    foreach ($result['onlyPort'] as $site) {
        echo "    only-port      {$site}\n";
    }

    foreach ($result['differingMessages'] as $site) {
        echo "    same site, different message  {$site}\n";
    }
}

echo "\ntotal: agree {$agree}, only-original {$onlyOriginal}, only-port {$onlyPort}\n";

exit($onlyOriginal === 0 && $onlyPort === 0 ? 0 : 1);
