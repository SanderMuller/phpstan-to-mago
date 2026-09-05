<?php

declare(strict_types=1);

/**
 * Every pinned corpus, one run each, recorded so a change is a diff rather than a memory.
 *
 *   php tests/Support/run-corpus-sweep.php [--record] [--corpus=name]
 *
 * `run-corpus-differential.php` answers one corpus once and prints to stdout. That is enough to investigate a
 * question and not enough to notice one: nothing re-runs, nothing is recorded, and a figure quoted from it
 * cannot be re-derived later. Two findings were lost that way — `Benchmark.php:27`'s cause is permanently
 * unknown for want of a captured version, and one finding appears at two different lines in two entries.
 *
 * **The corpora are this repository's own vendor trees.** That makes a run reproducible by `composer install`
 * rather than by having the right client project checked out, which is what every ad-hoc sweep in this
 * project's history depended on. It also means `composer update` moves the corpus, which is the point: the
 * recorded file then diffs, and an upstream release changing what either engine reports is visible.
 *
 * **This is a PHP entry point on purpose.** Three hand-rolled shell sweeps produced zero data before it
 * existed, each dying on a different missing binary inside a `nohup`'d subshell — `php`, then `grep`, then
 * `php` again inside mago's own extension host. Two of the three printed a "done" line per corpus while every
 * run had failed, because the progress marker could not fail. So this checks that each run produced a
 * `total:` line and aborts naming the corpus when one does not.
 */

use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * The pinned corpora, and why each is here.
 *
 * Four trees this repository already installs, chosen for shape rather than size: a parser with no framework,
 * a test framework, a rule-heavy codebase whose own rules this project transpiles, and a framework component.
 * Adding one is a line here plus a regenerated record.
 */
const CORPORA = [
    'php-parser' => 'vendor/nikic/php-parser/lib',
    'phpunit' => 'vendor/phpunit/phpunit/src',
    'rector' => 'vendor/rector/rector/src',
    'symfony-console' => 'vendor/symfony/console',
];

const RECORD = __DIR__ . '/../Fixtures/expected/corpus-sweep.md';

$root = dirname(__DIR__, 2);
/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$record = in_array('--record', $arguments, true);
$only = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--corpus=')) {
        $only = substr($argument, 9);
    }
}

/**
 * One corpus, or an abort naming it.
 *
 * The exit code is not the check. A differential that cannot start its own subprocess still exits 0 with an
 * explanation on stdout, which is how a sweep once reported ten successes and no data.
 *
 * @return array{files: int, agree: int, original: int, port: int, findings: list<string>}
 */
function sweep_one(string $root, string $name, string $path): array
{
    $sandbox = sys_get_temp_dir() . '/corpus-sweep-' . $name;
    $process = proc_open(
        ['php', $root . '/tests/Support/run-corpus-differential.php', $root, '--paths=' . $path, '--sandbox=' . $sandbox],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        Subprocess::environment(),
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "ABORT: could not start the differential for {$name}\n");

        exit(1);
    }

    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (preg_match('/^total: agree (\d+), only-original (\d+), only-port (\d+)$/m', $output, $totals) !== 1) {
        fwrite(STDERR, "ABORT: {$name} produced no `total:` line, so it ran nothing worth recording.\n");
        fwrite(STDERR, substr($output, -600) . "\n");

        exit(1);
    }

    preg_match('/^corpus: (\d+) files$/m', $output, $files);
    preg_match_all('/^\s+only-(original|port)\s+(\S+)$/m', $output, $each, PREG_SET_ORDER);

    $findings = [];
    foreach ($each as $one) {
        $findings[] = sprintf('%-9s %s', $one[1], str_replace($path . '/', '', $one[2]));
    }

    sort($findings);

    return [
        'files' => (int) ($files[1] ?? 0),
        'agree' => (int) $totals[1],
        'original' => (int) $totals[2],
        'port' => (int) $totals[3],
        'findings' => $findings,
    ];
}

$versions = [];
foreach (['mago', 'phpstan'] as $binary) {
    $process = proc_open(
        [$root . '/vendor/bin/' . $binary, '--version'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        Subprocess::environment(),
    );

    $line = '';
    if (is_resource($process)) {
        $line = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    $line = strtok($line, "\n");
    $versions[] = trim((string) preg_replace('/^(PHPStan)[^\d]*([\d.]+).*$/', '$1 $2', trim((string) $line)));
}

$lines = [
    '# Corpus sweep',
    '',
    'GENERATED by tests/Support/run-corpus-sweep.php --record. Do not edit by hand.',
    '',
    'Recorded against: ' . implode(', ', $versions),
    '',
    'Each corpus is a tree this repository installs, so `composer install` reproduces the run and',
    '`composer update` moves it — which is the point. A diff here is an upstream release changing what',
    'either engine reports, and both directions matter: a divergence closing is as worth reading as one',
    'opening. Every divergence is listed, because a count alone cannot show a compensating pair.',
    '',
];

foreach (CORPORA as $name => $path) {
    if ($only !== null && $only !== $name) {
        continue;
    }

    $result = sweep_one($root, $name, $path);
    printf(
        "  %-16s %5d files  agree %-6d only-original %-3d only-port %d\n",
        $name,
        $result['files'],
        $result['agree'],
        $result['original'],
        $result['port'],
    );

    $lines[] = sprintf(
        '## %s  %d files  agree %d, only-original %d, only-port %d',
        $name,
        $result['files'],
        $result['agree'],
        $result['original'],
        $result['port'],
    );
    $lines[] = '        ' . $path;
    foreach ($result['findings'] === [] ? ['— no divergence'] : $result['findings'] as $finding) {
        $lines[] = '        ' . $finding;
    }

    $lines[] = '';
}

if ($record && $only === null) {
    file_put_contents(RECORD, implode("\n", $lines));
    echo "\n  recorded to tests/Fixtures/expected/corpus-sweep.md\n";
} elseif ($record) {
    echo "\n  --record needs the whole sweep, not --corpus=\n";
}
