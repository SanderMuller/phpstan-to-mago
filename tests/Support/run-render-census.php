<?php

declare(strict_types=1);

/**
 * How much a type renderer would have to get right, counted on a real corpus.
 *
 *   php tests/Support/run-render-census.php <mago.toml-directory>
 *
 * 27 rule classes across the installed packages interpolate a rendered type into their message, and Mago's
 * `Type::__toString()` is measured to disagree with PHPStan's `describe(VerbosityLevel::typeOnly())` on four
 * shapes. This counts, over every type those rules read from — conditions, arithmetic operands, receivers —
 * how often those shapes actually occur, and which atomic kinds a renderer would meet.
 *
 * The point is to size the fallback before designing it: a renderer built on `$atomicTypes` needs a branch
 * per kind, and whether the exotic ones ever arrive is a fact about corpora rather than about the SDK.
 *
 * The directory must already hold a `mago.toml` pointing at the corpus — the differential's sandbox is one,
 * so a run is usually `--sandbox=DIR` from `run-corpus-differential.php` and then this against `DIR`.
 */
/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$directory = rtrim($arguments[0] ?? '', '/');
if ($directory === '' || ! is_file($directory . '/mago.toml')) {
    fwrite(STDERR, "Give a directory holding a mago.toml that points at the corpus.\n");

    exit(1);
}

$root = dirname(__DIR__, 2);
copy(__DIR__ . '/render-census/plugin.php', $directory . '/render-census-plugin.php');
file_put_contents($directory . '/render-census-worker.php', <<<PHP
    <?php

    declare(strict_types=1);

    // A notice on stdout corrupts the extension frame — mago reads binary frames there.
    ini_set('display_errors', 'stderr');

    use Mago\\Sdk\\Extension;
    use Mago\\Sdk\\Worker;

    require '{$root}/vendor/autoload.php';
    require __DIR__ . '/render-census-plugin.php';

    (new Worker(new Extension(
        identifier: 'census/render',
        name: 'Render census',
        version: '0.0.0',
        analyzerPlugins: [new RenderCensus\\Probe()],
    )))->run();
    PHP);

$configuration = (string) file_get_contents($directory . '/mago.toml');
file_put_contents(
    $directory . '/mago-render-census.toml',
    explode('[extension-hosts', $configuration)[0]
    . "[extension-hosts.census]\ncommand = [\"php\", \"render-census-worker.php\"]\n",
);

@unlink($directory . '/rows.jsonl');
$process = proc_open(
    [$root . '/vendor/bin/mago', '--config', 'mago-render-census.toml', 'analyze', '--reporting-format', 'json'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $directory,
);
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

$rows = $directory . '/rows.jsonl';
if (! is_file($rows)) {
    fwrite(STDERR, "The probe produced nothing. A fatal in the worker looks exactly like a hook that never fires.\n");

    exit(1);
}

$total = 0;
$flagged = 0;
$shapes = [];
$kinds = [];
$lines = file($rows, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines === false ? [] : $lines as $line) {
    /** @var array{k: list<string>, f: list<string>}|null $row */
    $row = json_decode($line, true);
    if (! is_array($row)) {
        continue;
    }

    ++$total;
    foreach ($row['k'] as $kind) {
        $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
    }

    if ($row['f'] !== []) {
        ++$flagged;
    }

    foreach ($row['f'] as $shape) {
        $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    }
}

arsort($shapes);
arsort($kinds);

printf("types observed: %d\n", $total);
printf("types Type::__toString() renders differently: %d (%.2f %%)\n\n", $flagged, $total === 0 ? 0.0 : $flagged / $total * 100);
foreach ($shapes as $shape => $count) {
    printf("  %8d  %s\n", $count, $shape);
}

printf("\ndistinct atomic kinds: %d\n", count($kinds));
foreach ($kinds as $kind => $count) {
    printf("  %8d  %s\n", $count, $kind);
}
