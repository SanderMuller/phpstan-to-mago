<?php

declare(strict_types=1);

/**
 * What the port costs against the rule packages it came from, on a consumer's own code.
 *
 *   php tests/Support/run-benchmark.php <consumer-root> [--paths=a,b] [--packages=one/rules] [--runs=N]
 *       [--sandbox=DIR]
 *
 * Four rows: the mago engine with no plugins, the same engine carrying the transpiled ones, PHPStan with a
 * cold result cache, and PHPStan with a warm one. Wall clock and CPU for each, best of `--runs`, with the
 * spread printed beside it.
 *
 * **The instrument is here because the number was not.** The README has carried a performance table for a
 * long time, measured with a harness that lived in a gitignored directory against a project that is not in
 * this repository — so a reader could not repeat it and nothing re-measured it when the runtime changed.
 * Every other figure this repository publishes has its instrument committed next to it; this one now does
 * too.
 *
 * Both sides read the consumer's own configuration, the same way `run-corpus-differential.php` does, so
 * neither engine is timed on a corpus the other never saw. Writes nothing into the consumer.
 *
 * Four things the guidelines ask of a measurement, and where each one is:
 *
 * - **Name what you compared against.** Every row prints its own command's shape, and the cold and warm
 *   PHPStan rows are separate rather than averaged, because they answer different questions.
 * - **Give the marginal cost.** The engine-only row exists so "mago plus the rules" can be read against
 *   "mago", which is the number a reader wants and the total never gives.
 * - **State n per row.** Printed, with the wall spread, because a 40-second run is not repeated as often as
 *   a 2-second one and a table that hides that is claiming a precision it does not have.
 * - **CPU and counts survive contention; wall clock does not.** Both are printed. Prefer the CPU column
 *   when the machine is shared.
 */

use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;
use Sandermuller\PhpstanToMago\Tests\Support\ResolutionRoots;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$consumer = null;
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-benchmark';
$paths = null;
$packages = null;
$runs = 3;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--paths=')) {
        $paths = explode(',', substr($argument, 8));
    } elseif (str_starts_with($argument, '--packages=')) {
        $packages = explode(',', substr($argument, 11));
    } elseif (str_starts_with($argument, '--sandbox=')) {
        $sandbox = substr($argument, 10);
    } elseif (str_starts_with($argument, '--runs=')) {
        $runs = max(1, (int) substr($argument, 7));
    } elseif (! str_starts_with($argument, '--')) {
        $consumer = $argument;
    }
}

if ($consumer === null) {
    fwrite(STDERR, "usage: run-benchmark.php <consumer-root> [--paths=a,b] [--packages=..] [--runs=N]\n");

    exit(1);
}

$root = dirname(__DIR__, 2);
$consumerRoot = (string) realpath(rtrim($consumer, '/'));
$configuration = CorpusDifferential::configurationOf($consumerRoot);
if (! is_file($configuration)) {
    fwrite(STDERR, "The consumer has neither phpstan.neon nor phpstan.neon.dist.\n");

    exit(1);
}

$differential = new CorpusDifferential(
    $root,
    $consumerRoot,
    $sandbox,
    $packages ?? [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
    ],
    $paths ?? ['src'],
);

$emitted = $differential->emit();
$magoConfig = $differential->writeMagoConfig();
$phpstanConfig = $differential->writePhpstanConfig();

// A cache directory this run owns, so the cold row is actually cold. The generated configuration includes
// the consumer's own, which sets `tmpDir` — clearing a directory PHPStan is not writing to produced a cold
// row identical to the warm one, twice, and it reads as "PHPStan's cache buys nothing" rather than as a
// broken instrument.
$cache = $sandbox . '/phpstan-cache';
$benchmarkConfig = $sandbox . '/phpstan-benchmark.neon';
file_put_contents($benchmarkConfig, <<<NEON
    includes:
        - {$phpstanConfig}

    parameters:
        tmpDir: {$cache}
    NEON);

// The same source set with no extension host at all, which is the only honest baseline for "what do the
// rules cost": measured against a plain run the host's own startup is charged to the rules, and measured
// against nothing at all there is no marginal figure to give.
$engineOnly = $sandbox . '/engine-only';
if (! is_dir($engineOnly) && ! mkdir($engineOnly, 0o777, true)) {
    fwrite(STDERR, "Could not create {$engineOnly}\n");

    exit(1);
}

$magoToml = (string) file_get_contents($magoConfig);
$hostAt = strpos($magoToml, '[extension-hosts');
file_put_contents($engineOnly . '/mago.toml', $hostAt === false ? $magoToml : substr($magoToml, 0, $hostAt));

/**
 * One run's wall clock and child CPU, which is where a subprocess's time is charged.
 *
 * `getrusage(1)` is `RUSAGE_CHILDREN`: the parent's own time is nearly nothing here and the engines' is
 * everything, so the child figure is the one worth reporting. Both are read either side of the call rather
 * than once at the end, because several rows run in one process.
 *
 * @param list<string> $command
 *
 * @return array{float, float}
 */
function benchmark_once(array $command, string $cwd): array
{
    $before = getrusage(1);
    $started = microtime(true);
    $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd, Subprocess::environment());
    if (is_resource($process)) {
        proc_close($process);
    }

    $wall = microtime(true) - $started;
    $after = getrusage(1);
    if (! is_array($before) || ! is_array($after)) {
        return [$wall, 0.0];
    }

    $cpu = 0.0;
    foreach (['ru_utime.tv_sec' => 1.0, 'ru_utime.tv_usec' => 0.000001, 'ru_stime.tv_sec' => 1.0, 'ru_stime.tv_usec' => 0.000001] as $field => $scale) {
        $end = $after[$field] ?? 0;
        $start = $before[$field] ?? 0;
        if (! is_int($end) || ! is_int($start)) {
            continue;
        }

        $cpu += ($end - $start) * $scale;
    }

    return [$wall, $cpu];
}

/**
 * One row: the best of `$runs`, with the spread beside it so a single-run row cannot read as three.
 *
 * The *minimum* rather than the mean, which is the usual choice for a timing whose noise is one-sided:
 * nothing makes a run faster than the machine allows, and everything else on the machine makes it slower.
 *
 * @param list<string> $command
 */
function benchmark_row(string $label, array $command, string $cwd, int $runs, ?callable $before = null): void
{
    $walls = [];
    $cpus = [];
    for ($run = 0; $run < $runs; $run++) {
        if ($before !== null) {
            $before();
        }

        [$wall, $cpu] = benchmark_once($command, $cwd);
        $walls[] = $wall;
        $cpus[] = $cpu;
    }

    if ($walls === [] || $cpus === []) {
        return;
    }

    printf(
        "  %-34s %7.2fs %8.2fs   n=%d  wall spread %.2fs\n",
        $label,
        min($walls),
        min($cpus),
        $runs,
        max($walls) - min($walls),
    );
}

$clearCache = static function () use ($cache): void {
    if (! is_dir($cache)) {
        return;
    }

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
};

$mago = $root . '/vendor/bin/mago';
$phpstan = $root . '/vendor/bin/phpstan';
$phpstanCommand = [$phpstan, 'analyse', '-c', $benchmarkConfig, '--memory-limit=2G', '--no-progress'];

printf("%s  (%d files)\n", $consumerRoot, count($differential->corpusFiles()));
printf("  emitted:  %d rule(s), refused %d (target: php)\n", $emitted['emitted'], $emitted['refused']);
printf("  includes: %d resolution root(s)\n", count(ResolutionRoots::of($consumerRoot, [$consumerRoot])));
printf("  %-34s %8s %9s\n", '', 'wall', 'CPU');

benchmark_row('mago, engine only', [$mago, 'analyze'], $engineOnly, $runs);
benchmark_row('mago + the transpiled rules', [$mago, 'analyze'], $sandbox, $runs);
benchmark_row('PHPStan, cold result cache', $phpstanCommand, $sandbox, $runs, $clearCache);
benchmark_row('PHPStan, warm result cache', $phpstanCommand, $sandbox, $runs);

echo "\n  Read the marginal cost off the first two rows, and quote the PHPStan row you compared against.\n";
