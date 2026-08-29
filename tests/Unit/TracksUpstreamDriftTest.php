<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Transpiler;
use SplFileInfo;

/**
 * The census: what this transpiler does with every rule in the corpus, pinned as a file.
 *
 * The snapshots under `Fixtures/expected` pin what one rule *emits*. This pins the outcome for *all* of
 * them — emitted, or refused with which reason — which is the only thing that turns an upstream change into
 * a readable diff instead of a scattered failure somewhere else in the suite.
 *
 * The reason is on the line because it is the other half of what this tool produces, and it was the half
 * nothing reviewed. Four refusals in one week were found to name a construct that was not what stopped the
 * rule — an unwired configured list refusing on `in_array()`, a rule that only calls
 * `$scope->invokeNodeCallback()` refusing on a missing node predicate, type inference refusing on
 * `Scalar_Int`, and a renderer nobody had written refusing as though the SDK withheld the data. Each read as
 * one table row away. None would have survived arriving here as a diff.
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
     * The list said four for a long time while `composer.json` required seven, so the sentence above was
     * false about its own repository: `phpstan/phpstan-strict-rules`, `phpstan/phpstan-phpunit` and
     * `phpstan/phpstan-deprecation-rules` ship 58 rules between them and the census spoke for none of them.
     * They are here now, which is what makes the denominator the number of rules installed rather than the
     * number someone remembered to list.
     *
     * @var list<string>
     */
    private const array PACKAGES = [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
        'phpstan/phpstan-strict-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-deprecation-rules',
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
        Transpiler::$allowUnverified = false;
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
            . 'a rule whose body changed into (or out of) a shape the vocabulary covers, or a refusal that now '
            . 'names a different obstacle. Read it, decide whether the new outcome *and its reason* are right, '
            . 'and only then replace the census with the .actual file beside it — the same discipline the '
            . 'emitted-output snapshots hold to.',
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
            'One line per rule in the packages this repository installs, and under a refused one the reason.',
            'A diff here is upstream drift — a rule added, removed, or rewritten into a shape the vocabulary',
            'does or does not cover — or a change in what a refusal says stops a rule. Both are worth reading:',
            'a refusal naming the wrong obstacle is how work gets sized wrongly.',
            '',
            'A refused rule also lists what its body `needs:`, which is the half a first blocker never says.',
            'Sizing work from the first obstacle alone has been wrong three times here — a renderer that looked',
            'like one customer, a five-rule family that looked like one missing navigation, a corpus that looked',
            'absent. Grep a capability to count what it is worth before building it.',
            '',
            'Grep the whole line, not the label it starts with. Some needs share an outer phrase and nothing',
            'else: `assignment value outside the vocabulary` covers seven rules and seven unrelated problems —',
            'a statement kind, four different access paths, an array search and a guard shape. Counted by its',
            'label it ties for the largest cluster here and is not a cluster at all. A need is one capability',
            'only where the text after the label is the same text.',
            '',
            'The list is a **lower bound**. A statement that refuses is stepped over and the next one is',
            'translated, so obstacles in different statements all appear and a second one inside a single',
            'statement does not; a rule blocked early shows less than it needs.',
            '',
            'It is also a lower bound on **translation**, which is a different denominator from **feasibility**.',
            "A refusal says this rule's body cannot be translated statement by statement. It does not say the",
            'rule cannot run on mago: a PHPStan helper ported into the runtime, or a rule expressed through a',
            'question mago answers directly, moves a rule without changing a line of its body. Both denominators',
            'have already been quoted as the other one here — a synthesised-node call was read as a permanent',
            'ceiling when it sat in a branch guarding an operator-overloading tail. Expect them to diverge, and',
            'say which one a number is.',
        ];

        foreach (self::PACKAGES as $package) {
            $source = dirname(__DIR__, 2) . '/vendor/' . $package . '/src';
            $outcomes = [];
            $files = [];
            foreach (RulePaths::expand(is_dir($source) ? [$source] : []) as $file) {
                $name = basename($file, '.php');
                $outcomes[$name] = $this->outcome($file);
                $files[$name] = $file;
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

                if ($outcome === 'EMIT') {
                    $lines[] = 'EMIT    ' . $name . $where;

                    continue;
                }

                // The reason is on the line, because it is the other half of what this tool produces and was
                // the half nothing reviewed. Four refusals in one week named a construct that was not what
                // stopped the rule, each read as one table row away, and none of them would have survived
                // arriving as a diff here.
                $needs = $this->needs($files[$name]);
                $lines[] = 'REFUSE  ' . $name . $where . "\n        " . substr($outcome, strlen('REFUSE '))
                    . ($needs === [] ? '' : "\n        needs: " . implode("\n        needs: ", $needs));
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
    /**
     * `EMIT`, or `REFUSE ` and the reason.
     *
     * Only a `Refusal` is caught. A broader catch turned any crash into a refusal whose reason was the crash,
     * which reads as a vocabulary gap and is not one; nothing in the corpus throws anything else today, so
     * letting it fail loudly costs nothing and a bug cannot hide as an outcome.
     */
    /**
     * Everything a refused rule's body needs, rather than only what stops it first.
     *
     * The half the census was missing, and the half work gets sized from. A first blocker says what to fix
     * next; it never says what a fix is worth, and reading it as though it did has been wrong three times —
     * the type renderer looked like one customer where 27 rules interpolate a rendered type, a five-rule
     * family looked like one missing navigation where it needs that *and* the renderer, and a whole corpus
     * looked absent because the walk that would have read it stopped for an unrelated reason.
     *
     * A lower bound, and the shape of the collection is why: a statement that refuses is stepped over and
     * the next one translated, so obstacles in *different* statements all appear, and a second obstacle
     * inside one statement does not. `MatchingTypeInSwitchCaseConditionRule` shows `->cond` and `->cases`
     * and not the `describe()` inside the loop it could not enter.
     *
     * @return list<string>
     */
    private function needs(string $file): array
    {
        $survey = Transpiler::$survey;
        Transpiler::$survey = true;

        Transpiler::$collectNeeds = true;

        try {
            $transpiler = new Transpiler($file);

            try {
                $transpiler->transpile();
            } catch (Refusal) {
                // The verdict is the caller's; this pass is only here for the list it collected on the way.
            }

            $needs = array_map(
                static fn (string $need): string => trim((string) preg_replace('/ \(line \d+\)/', '', $need)),
                $transpiler->needs(),
            );

            // `unknown local $x` is not a capability the rule needs; it is what stepping over the statement
            // that bound `$x` produces. Keeping those would make every skipped assignment cost two lines and
            // read as two gaps.
            $needs = array_filter(
                $needs,
                static fn (string $need): bool => ! str_starts_with($need, 'unknown local $'),
            );

            // First sentence only. A needs entry is a *label* for sizing, and one refusal's full text runs to a
            // paragraph — repeated across the 27 rules that share it, the census would be mostly that
            // paragraph. The line above it still carries the whole reason for whichever rule it stops.
            return array_values(array_map(
                static fn (string $need): string => explode('. ', $need)[0],
                $needs,
            ));
        } finally {
            Transpiler::$survey = $survey;
            Transpiler::$collectNeeds = false;
        }
    }

    private function outcome(string $file): string
    {
        try {
            (new Transpiler($file))->transpile();

            return 'EMIT';
        } catch (Refusal $refusal) {
            // Line numbers stripped, and every occurrence rather than the last: a nested refusal carries the
            // inner construct's line as well as the outer one's. A construct moving down a file is not drift.
            return 'REFUSE ' . trim((string) preg_replace('/ \(line \d+\)/', '', $refusal->getMessage()));
        }
    }
}
