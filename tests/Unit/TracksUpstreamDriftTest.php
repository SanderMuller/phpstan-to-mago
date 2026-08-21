<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Transpiler;
use SplFileInfo;
use Throwable;

/**
 * The census: what this transpiler does with every rule in the corpus, pinned as a file.
 *
 * The snapshots under `Fixtures/expected` pin what one rule *emits*. This pins the outcome for *all* of
 * them — emitted, or refused with which reason — which is the only thing that turns an upstream change into
 * a readable diff instead of a scattered failure somewhere else in the suite.
 *
 * It matters because `composer.lock` is not committed, so every CI run resolves the rule packages afresh.
 * An upstream release that adds a rule, deletes one, or rewrites one into a shape the vocabulary does not
 * cover shows up here as a one-line diff naming it. Without this the same release either passes silently
 * (a new rule nobody translated) or fails in whichever test happened to touch it.
 *
 * Two deliberate choices about what the census does *not* record:
 *
 * - **No package versions.** A bump that changes nothing about the translation would otherwise fail, and a
 *   drift alert that fires on every routine bump is one nobody reads.
 * - **No line numbers.** They are stripped from refusal reasons, because a construct moving down a file is
 *   not drift. Which construct blocks a rule is; that stays.
 *
 * The census is for the `php` target, the one the package ships. A count without its target invites two
 * correct numbers to look like a contradiction.
 */
final class TracksUpstreamDriftTest extends TestCase
{
    private const string CENSUS = __DIR__ . '/../Fixtures/expected/census.md';

    /**
     * The rule packages this repository installs, which is what the census speaks for.
     *
     * Each is a dev dependency so that a hosted runner resolves the same corpus a contributor does — a census
     * that depended on whose machine ran it would be worthless. `hihaho/phpstan-rules` is installed to be
     * *read* rather than run: `composer.json` tells the extension installer to ignore it, because registering
     * its rules against this repository's own source is not what a corpus is for.
     *
     * @var list<string>
     */
    private const array PACKAGES = [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
    ];

    /**
     * Rules whose finding the engine already reports itself, and the diagnostic that does it.
     *
     * A third outcome, because "refused" reads as a gap and this is not one: a consumer switching loses
     * nothing. `NoMissingVariableDimFetchRule` has no route — Mago's `possiblyUndefined` tracks only the `try`
     * case, so there is nothing to build against without approximating — and needs none, because
     * `undefined-variable` fires on the same code, once per site since mago#2219.
     *
     * Curated rather than derived: whether two tools report the same thing is a judgement, and one made per
     * rule with the diagnostic named is a judgement a reader can check.
     *
     * @var array<string, string>
     */
    private const array ENGINE_COVERED = [
        'NoMissingVariableDimFetchRule' => 'undefined-variable',
    ];

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverifiedAggregates = false;
    }

    public function test_the_corpus_still_translates_the_way_the_census_says(): void
    {
        $census = $this->census();
        $committed = is_file(self::CENSUS) ? (string) file_get_contents(self::CENSUS) : '';

        if ($census !== $committed) {
            file_put_contents(self::CENSUS . '.actual', $census);
        }

        $this->assertSame(
            $committed,
            $census,
            "The corpus no longer translates the way the census records.\n\n"
            . 'This is the upstream-drift alarm, and the diff names what moved: a rule added, a rule removed, '
            . 'or a rule whose body changed into (or out of) a shape the vocabulary covers. Read it, decide '
            . 'whether the new outcome is right, and only then replace the census with the .actual file beside '
            . 'it — the same discipline the emitted-output snapshots hold to.',
        );
    }

    public function test_the_census_is_not_empty_by_accident(): void
    {
        // Guards the guard. If the packages are not installed, every rule vanishes and the census becomes a
        // header — which would compare equal to a census someone regenerated in that state, and the alarm
        // would be permanently silent.
        $this->assertGreaterThan(100, substr_count($this->census(), "\n"));
        $this->assertStringContainsString('EMIT    UppercaseConstantRule', $this->census());
    }

    /** The census text: every rule in every installed package, with what this transpiler does with it. */
    private function census(): string
    {
        $lines = [
            '# Corpus census',
            '',
            'GENERATED by tests/Unit/TracksUpstreamDriftTest.php for the `php` target. Do not edit by hand.',
            '',
            'One line per rule in the packages this repository installs. A diff here is upstream drift: a rule',
            'added, removed, or rewritten into a shape the vocabulary does or does not cover.',
        ];

        foreach (self::PACKAGES as $package) {
            $source = dirname(__DIR__, 2) . '/vendor/' . $package . '/src';
            $outcomes = [];
            foreach (RulePaths::expand(is_dir($source) ? [$source] : []) as $file) {
                $name = basename($file, '.php');
                $outcomes[$name] = $this->outcome($file);
            }

            // Sorted by name, because `RulePaths` walks the filesystem and its order is not the same on every
            // machine — an unsorted census would diff against itself between a laptop and a runner.
            ksort($outcomes);

            $registered = $this->registeredClasses($package);
            $named = array_filter(
                $outcomes,
                static fn (string $outcome, string $name): bool => isset($registered[$name]),
                ARRAY_FILTER_USE_BOTH,
            );
            $emitted = count(array_filter($named, static fn (string $outcome): bool => $outcome === 'EMIT'));
            $engine = count(array_filter(
                $named,
                static fn (string $outcome, string $name): bool => $outcome !== 'EMIT' && isset(self::ENGINE_COVERED[$name]),
                ARRAY_FILTER_USE_BOTH,
            ));

            $lines[] = '';
            $lines[] = sprintf(
                '## %s — %d of %d the package registers emit, %d covered by the engine, %d refuse, %d it registers nowhere',
                $package,
                $emitted,
                count($named),
                $engine,
                count($named) - $emitted - $engine,
                count($outcomes) - count($named),
            );
            $lines[] = '';
            foreach ($outcomes as $name => $outcome) {
                // A class the package names in no neon of its own — not a dead one. A consumer registers rules
                // by hand all the time: `StringFileAbsolutePathExistsRule` is marked here and is the first
                // entry in `../hihaho`'s own `rules:` list. What the mark rules out is counting it against the
                // package's own coverage, which overstated the gap by thirteen rules for `hihaho`.
                $where = isset($registered[$name]) ? '' : '  (the package registers it nowhere)';

                // A rule the engine already covers is not a gap, so it does not read as one. The diagnostic is
                // named on the line, because "covered" without saying by what is a claim nobody can check.
                if ($outcome !== 'EMIT' && isset(self::ENGINE_COVERED[$name])) {
                    $lines[] = 'ENGINE  ' . $name . '  (mago reports ' . self::ENGINE_COVERED[$name] . ')' . $where;

                    continue;
                }

                $lines[] = ($outcome === 'EMIT' ? 'EMIT    ' : 'REFUSE  ') . $name . $where;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The rule classes a package names in a neon of its own, by short name.
     *
     * The denominator that matters is what a package actually wires. `hihaho/phpstan-rules` ships twenty rule
     * classes and registers seven; the other thirteen are the un-merged originals its `Combined*` rules absorb,
     * and counting them as unfinished work overstated the gap by thirteen. Their "constructor parameter the
     * neon does not wire" refusals are final for a reason no feature reaches: nothing constructs them, so
     * PHPStan never runs them and a differential would have nothing to compare against.
     *
     * Every neon the package ships counts, not only the auto-included ones. Registration is a *consumer* fact:
     * `symplify/phpstan-rules` auto-includes four of its thirteen config files and puts most rules behind
     * `conditionalTags` that default off, so a consumer lists them by hand — as `../hihaho` does with eleven.
     * Keyed on auto-inclusion, symplify would read as zero of ninety-five, which is a worse denominator than
     * the one this replaces rather than a better one.
     *
     * @return array<string, true>
     */
    private function registeredClasses(string $package): array
    {
        $root = dirname(__DIR__, 2) . '/vendor/' . $package;
        $found = [];

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'neon') {
                continue;
            }

            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                $found[substr($reference, (int) strrpos($reference, '\\') + 1)] = true;
            }
        }

        return $found;
    }

    /** Whether the rule translates, which is the whole of what this file records. */
    private function outcome(string $file): string
    {
        try {
            (new Transpiler($file))->transpile();

            return 'EMIT';
        } catch (Throwable) {
            return 'REFUSE';
        }
    }
}
