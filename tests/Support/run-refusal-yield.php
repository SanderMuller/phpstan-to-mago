<?php

declare(strict_types=1);

/**
 * What each refused rule would be worth: how many findings it produces on real code.
 *
 *   php tests/Support/run-refusal-yield.php <tree> [<tree> ...]
 *
 * **The census counts rules and this counts findings, which is a different question and the one that decides
 * work.** A needs list says what a rule is missing; it never says what the rule is for. Measured across five
 * vendored trees, 3630 files: seven refused rules produce 982 findings and thirty-five produce none. Sizing
 * from the refusal list alone ranks those forty-two by the shape of their first blocker, which is unrelated
 * to whether anyone would see the rule fire.
 *
 * The precedent is `NoJustPropertyAssignRule`, sized at 8 findings on 4000 files after two capabilities had
 * been costed for it -- a statement-level docblock position and a PHPDoc type parser. The cost was known and
 * the benefit was not, and the benefit was the smaller number.
 *
 * **PHPStan's own baseline is the transport, and that is not a convenience.** Reading findings off stdout
 * gave five consecutive zeros in this environment, because the wrapper around the analyser truncates the
 * per-file detail list -- 174 errors arrived with 9 files detailed, and 4650 in 5.6 kB. `--generate-baseline`
 * is written by PHPStan itself and is complete, which is why the run below asks for one and parses it rather
 * than reading the report.
 *
 * Two things this cannot see, printed beside the table rather than left for a reader to discover:
 *
 * - **A rule whose identifier is not a literal contributes no row.** {@see ReportedIdentifiers} reads literal
 *   `->identifier('..')` arguments, `sprintf` over literals and ternaries over literals. A rule spelling its
 *   identifier as a class constant or interpolating it has no identifier here, so its zero means *unreadable*
 *   rather than *silent*. `SeeAnnotationToTestRule` is one: `RuleIdentifier::SEE_ANNOTATION_TO_TEST`.
 * - **A zero belongs to these trees.** A rule about Symfony configuration or Doctrine mappings fires nowhere
 *   in a tree that holds neither, and that is a fact about the corpus rather than about the rule.
 */

use Sandermuller\PhpstanToMago\PackageCoverage;
use Sandermuller\PhpstanToMago\ReportedIdentifiers;
use Sandermuller\PhpstanToMago\RuleOutcome;
use Sandermuller\PhpstanToMago\Tests\Support\LockedCorpus;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;
use Sandermuller\PhpstanToMago\Transpiler;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $trees */
$trees = array_values(array_filter(
    array_slice((array) ($_SERVER['argv'] ?? []), 1),
    static fn (mixed $argument): bool => is_string($argument) && ! str_starts_with($argument, '--'),
));

if ($trees === []) {
    fwrite(STDERR, "usage: run-refusal-yield.php <tree> [<tree> ...]\n");

    exit(1);
}

$root = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-yield';
if (! is_dir($sandbox) && ! mkdir($sandbox, 0o777, true)) {
    fwrite(STDERR, "Could not create {$sandbox}\n");

    exit(1);
}

$absolute = [];
$files = 0;
foreach ($trees as $tree) {
    $path = (string) realpath(str_starts_with($tree, '/') ? $tree : $root . '/' . $tree);
    if (! is_dir($path)) {
        fwrite(STDERR, "Not a directory: {$tree}\n");

        exit(1);
    }

    $absolute[] = $path;
    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            ++$files;
        }
    }
}

// No `includes`. Every corpus package registers its own rules through `phpstan/extension-installer`, and
// naming one as well makes PHPStan abort with "included multiple times" -- which reads as a configuration
// mistake and is actually the installer having done the job already. The two thresholds silence the
// whole-project rules, whose findings are per file and would swamp the per-rule counts.
$configuration = $sandbox . '/phpstan.neon';
file_put_contents($configuration, implode("\n", [
    'parameters:',
    '    level: 0',
    '    customRulesetUsed: true',
    '    tmpDir: ' . $sandbox . '/cache',
    '    paths:',
    ...array_map(static fn (string $path): string => '        - ' . $path, $absolute),
    '    type_coverage:',
    '        return: 0',
    '        param: 0',
    '        property: 0',
    '        constant: 0',
    '        declare: 0',
    '    cognitive_complexity:',
    '        class: 100000',
    '        function: 100000',
    '',
]));

$baseline = $sandbox . '/findings.neon';
@unlink($baseline);

$process = proc_open(
    [$root . '/vendor/bin/phpstan', 'analyse', '-c', $configuration, '--no-progress', '--generate-baseline=' . $baseline],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $sandbox,
    Subprocess::environment(),
);
if (is_resource($process)) {
    proc_close($process);
}

if (! is_file($baseline)) {
    fwrite(STDERR, "PHPStan wrote no baseline, so nothing was measured. Run it by hand against {$configuration}.\n");

    exit(1);
}

// Baseline entries are grouped by message and path and carry a `count`, so the findings are the summed
// counts rather than the number of entries. Counting entries under-reports every message that repeats in one
// file, which is most of them.
$text = (string) file_get_contents($baseline);
preg_match_all('/identifier: (\S+)\n\s+count: (\d+)/', $text, $matches, PREG_SET_ORDER);

/** @var array<string, int> $findings */
$findings = [];
foreach ($matches as $match) {
    $findings[$match[1]] = ($findings[$match[1]] ?? 0) + (int) $match[2];
}

$target = Transpiler::$target;
$survey = Transpiler::$survey;
Transpiler::$target = 'php';
Transpiler::$survey = false;

try {
    $rows = [];
    $unreadable = 0;
    foreach (LockedCorpus::PACKAGES as $package) {
        $source = $root . '/vendor/' . $package;
        if (! is_dir($source)) {
            continue;
        }

        // Every class file in the package, not the rules: `symplify/phpstan-rules` holds its identifiers in
        // a `RuleIdentifier` class, and reaching the value means reaching that file.
        $siblings = [];
        /** @var iterable<SplFileInfo> $classes */
        $classes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($classes as $class) {
            if ($class->isFile() && $class->getExtension() === 'php') {
                $siblings[$class->getBasename('.php')] = $class->getPathname();
            }
        }

        foreach (PackageCoverage::forPackage($package, $source)->outcomes as $outcome) {
            if ($outcome->verdict !== RuleOutcome::REFUSE) {
                continue;
            }

            $identifiers = ReportedIdentifiers::of($outcome->file, $siblings, true);
            if ($identifiers === []) {
                ++$unreadable;

                continue;
            }

            $total = 0;
            foreach ($identifiers as $identifier) {
                $total += $findings[$identifier] ?? 0;
            }

            $rows[] = [$total, $outcome->name, $identifiers];
        }
    }
} finally {
    Transpiler::$target = $target;
    Transpiler::$survey = $survey;
}

usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

printf("%d file(s) across %d tree(s), rules registered by extension-installer\n\n", $files, count($absolute));
printf("  %8s  %-46s %s\n", 'findings', 'refused rule', 'identifier(s)');
$firing = 0;
$sum = 0;
foreach ($rows as [$total, $name, $identifiers]) {
    if ($total > 0) {
        ++$firing;
        $sum += $total;
    }

    printf("  %8d  %-46s %s\n", $total, $name, implode(', ', $identifiers));
}

printf(
    "\n  %d of %d refused rules fire here, for %d findings. %d more have no readable identifier, so their\n"
    . "  absence from this table is *unreadable* rather than silent -- a rule spelling its identifier as a\n"
    . "  class constant or interpolating it cannot be joined. And a zero belongs to these trees: a rule about\n"
    . "  a framework they do not contain fires nowhere for a reason that is not about the rule.\n",
    $firing,
    count($rows),
    $sum,
    $unreadable,
);
