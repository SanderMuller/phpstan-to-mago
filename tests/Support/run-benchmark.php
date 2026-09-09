<?php

declare(strict_types=1);

/**
 * What the port costs against the rule packages it came from, on a consumer's own code.
 *
 *   php tests/Support/run-benchmark.php <consumer-root> [--paths=a,b] [--packages=one/rules] [--runs=N]
 *       [--sandbox=DIR]
 *
 * Three mago rows per include set -- the engine with no host, the engine carrying a host that registers
 * nothing, and the engine carrying the transpiled rules -- then PHPStan with a cold result cache and with a
 * warm one. Wall clock and CPU for each, best of `--runs`, with the spread printed beside it.
 *
 * **Two include sets, because mago's `includes` is the largest term in its runtime and the two that matter
 * are not the same one.** The snippet this tool emits hands a consumer the set the emitted rules derive; the
 * corpus differential points mago at all of `vendor` so neither engine is blind to a vendored ancestor. A
 * table quoting one of those alone reads as general and is not: the same 97 plugins on the same corpus differ
 * by more than 3x between them. So both are measured here, each labelled with its root and file counts, and
 * the findings each reports are printed untimed -- a narrower set that indexes fewer files can leave a rule
 * unable to resolve an ancestor, and such a rule goes quiet rather than failing.
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

use Sandermuller\PhpstanToMago\RecommendedIncludes;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;
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

// The include set the emitted rules actually need, composed the way `bin/phpstan-to-mago` composes the
// snippet it ships: glob the emitted plugin directory and hand it to {@see RecommendedIncludes}. Building a
// file list here instead would measure a configuration nobody ships, which is the error the second block
// exists to correct -- the README carried an all-of-vendor reading for a long time and read as general.
// Quoted here rather than there: `RecommendedIncludes` answers bare absolute paths and the snippet writer
// quotes them, so handing them straight to a TOML `includes = [..]` writes a file mago refuses to parse. It
// did, and the block read 0.05s with no findings -- a broken run that looks exactly like a fast one, which is
// what the parity row below exists to catch.
$plugins = glob($sandbox . '/plugins/*.php');
$recommended = array_map(
    static fn (string $root): string => '"' . $root . '"',
    RecommendedIncludes::forEmitted($plugins === false ? [] : $plugins),
);

$magoToml = (string) file_get_contents($magoConfig);

// A worker that starts, speaks the protocol and registers nothing, so each include set can carry a no-op
// host of its own. Without that row the engine-only one charges the host's own startup and per-node protocol
// traffic to the rules, and this repository has already recorded getting the opposite conclusion from the
// two baselines -- a mago reverse index read as 23% of a plain run and 12% of a run with a no-op host, where
// starting the host cost more than the index did. The rules cost the third row against the second, and both
// have to sit under the same `includes` or the subtraction crosses configurations.
$noopWorker = $sandbox . '/noop-worker.php';
file_put_contents($noopWorker, <<<PHP
    <?php

    declare(strict_types=1);

    ini_set('display_errors', 'stderr');

    use Mago\Sdk\Extension;
    use Mago\Sdk\Worker;

    require '{$root}/vendor/autoload.php';

    (new Worker(new Extension(
        identifier: 'benchmark/noop',
        name: 'No plugins at all',
        version: '0.0.0',
        analyzerPlugins: [],
    )))->run();
    PHP);

/**
 * One directory holding one `mago.toml`: the sandbox's, with its include set and its host swapped.
 *
 * @param list<string> $includes already quoted for TOML
 * @param ?string      $worker    absolute; null drops the host section entirely
 */
function variant_dir(string $sandbox, string $name, string $magoToml, array $includes, ?string $worker): string
{
    $directory = $sandbox . '/' . $name;
    if (! is_dir($directory) && ! mkdir($directory, 0o777, true)) {
        fwrite(STDERR, "Could not create {$directory}\n");

        exit(1);
    }

    $toml = (string) preg_replace(
        '/^includes = \[.*\]$/m',
        'includes = [' . implode(', ', $includes) . ']',
        $magoToml,
        1,
    );

    if ($worker === null) {
        $hostAt = strpos($toml, '[extension-hosts');
        $toml = $hostAt === false ? $toml : substr($toml, 0, $hostAt);
    } else {
        // `worker.php` is resolved against the directory mago runs in, so every variant names it absolutely.
        $toml = str_replace('command = ["php", "worker.php"]', 'command = ["php", "' . $worker . '"]', $toml);
    }

    file_put_contents($directory . '/mago.toml', $toml);

    return $directory;
}

/**
 * The include roots the written configuration actually names, unquoted.
 *
 * Read back out of the file rather than recomputed. Two derivations of the same list is how a table comes to
 * describe one configuration and be measured on another, and only one of them is the configuration mago read.
 *
 * @return list<string>
 */
function include_roots(string $magoToml): array
{
    if (preg_match('/^includes = \[(.*)\]$/m', $magoToml, $match) !== 1) {
        return [];
    }

    $roots = [];
    foreach (explode(',', $match[1]) as $entry) {
        $root = trim(trim($entry), '"');
        if ($root !== '') {
            $roots[] = $root;
        }
    }

    return $roots;
}

/**
 * How many PHP files an include set puts in front of the indexer.
 *
 * The root count is not the figure that matters: {@see RecommendedIncludes} measures the cost per *file* and
 * slightly superlinear, so one root holding fourteen thousand files and eleven holding sixty are the two ends
 * of the table and the root count reads them the wrong way round.
 *
 * @param list<string> $roots
 */
function include_files(array $roots): int
{
    $files = 0;
    foreach ($roots as $root) {
        if (is_file($root)) {
            ++$files;

            continue;
        }

        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                ++$files;
            }
        }
    }

    return $files;
}

/**
 * How many findings the transpiled rules report from one directory, untimed.
 *
 * **This is the discriminating check, not a decoration.** A narrower include set indexes fewer files, and a
 * rule that cannot resolve an ancestor goes *quiet* rather than failing -- `NoPropertyNodeAssignRule` did
 * exactly that on the file-level include set {@see RecommendedIncludes} records rejecting. A faster row with
 * fewer findings is a quieter run, and the timed rows send output to /dev/null and cannot see it.
 */
function transpiled_findings(string $mago, string $cwd): int
{
    $process = proc_open(
        [$mago, 'analyze', '--reporting-format', 'json'],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $cwd,
        Subprocess::environment(),
    );

    if (! is_resource($process)) {
        return -1;
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    /** @var array{issues?: list<array{code?: string}>}|null $decoded */
    $decoded = json_decode($output, true);
    if (! is_array($decoded)) {
        return -1;
    }

    $findings = 0;
    foreach ($decoded['issues'] ?? [] as $issue) {
        // Mago reports its own native diagnostics on the same run; only the transpiled rules' count.
        if (str_starts_with((string) ($issue['code'] ?? ''), 'transpiled/')) {
            ++$findings;
        }
    }

    return $findings;
}

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
    for ($run = 0; $run < $runs; ++$run) {
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

// Both include sets, in one instrument, each labelled. The shipped snippet hands a consumer the derived set
// and the differential runs the vendor-wide one, so a table quoting either alone is unrepresentative of the
// other -- and the sentence built on it inherits that. A count belongs to its configuration.
$blocks = [
    ['the include set the emitted rules need', 'derived', $recommended],
    ["all of vendor plus the consumer's autoload roots", 'vendor', array_map(
        static fn (string $root): string => '"' . $root . '"',
        include_roots($magoToml),
    )],
];

$parity = [];
foreach ($blocks as [$label, $slug, $includes]) {
    $host = variant_dir($sandbox, $slug . '-host', $magoToml, $includes, $sandbox . '/worker.php');
    printf(
        "\n  includes: %s -- %d root(s), %d file(s)\n",
        $label,
        count($includes),
        include_files(include_roots((string) file_get_contents($host . '/mago.toml'))),
    );

    $parity[$label] = transpiled_findings($mago, $host);
    printf("  %-34s %8s %9s\n", '', 'wall', 'CPU');
    benchmark_row('mago, engine only', [$mago, 'analyze'], variant_dir($sandbox, $slug . '-engine', $magoToml, $includes, null), $runs);
    benchmark_row('mago + a host with no plugins', [$mago, 'analyze'], variant_dir($sandbox, $slug . '-noop', $magoToml, $includes, $noopWorker), $runs);
    benchmark_row('mago + the transpiled rules', [$mago, 'analyze'], $host, $runs);
}

printf("\n  %-34s %8s %9s\n", '', 'wall', 'CPU');
benchmark_row('PHPStan, cold result cache', $phpstanCommand, $sandbox, $runs, $clearCache);
benchmark_row('PHPStan, warm result cache', $phpstanCommand, $sandbox, $runs);

echo "\n  findings, untimed:\n";
foreach ($parity as $label => $findings) {
    printf("    %-52s %d\n", $label, $findings);
}

if (count(array_unique(array_values($parity))) > 1) {
    echo "\n  STOP. The include sets do not report the same findings, so the faster row is the quieter one\n"
        . "  rather than the cheaper one. A rule that cannot resolve an ancestor goes silent; do not quote\n"
        . "  either row until they agree.\n";
}

echo "\n  The rules cost row three of a block against row two of the same block. Row two against row one is\n"
    . "  what the host costs, which is not the rules' to carry -- and quote the PHPStan row you compared\n"
    . "  against, and the include set the mago row was measured under.\n";
