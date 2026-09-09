<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What this transpiler does with every rule one package ships.
 *
 * The shared half of two artefacts that report the same facts for different readers: the corpus census,
 * which pins this repository's own dependencies so an upstream release arrives as a readable diff, and the
 * status page, which tells a consumer what their install actually runs. Both need the same four-way split
 * and the same reasons; only the prose around them differs. Written once here, because two renderers over
 * one model cannot disagree about a count and two models can.
 *
 * Four denominators, and the difference between them has been got wrong here more than once:
 *
 * - **ships** — every rule class in the package. The most generous, and the least useful: `hihaho` ships
 *   twenty and wires seven, so this number reads as thirteen rules of unfinished work that nothing could
 *   ever run.
 * - **registers** — what the package names in a neon of its own. A rule it wires nowhere cannot run for
 *   anybody, whatever this tool does with it.
 * - **portable** — registered, minus the rules no plugin could carry. The denominator a coverage figure has
 *   to quote, or it names a target this tool will never reach.
 * - **emitted** — what translates today.
 */
final readonly class PackageCoverage
{
    /** @param list<RuleOutcome> $outcomes sorted by rule name */
    private function __construct(
        public string $package,
        public string $root,
        public array $outcomes,
    ) {}

    /**
     * Every rule under a package root, with what the transpiler makes of it.
     *
     * Sorted by name, because {@see RulePaths} walks the filesystem and its order is not the same on every
     * machine — an unsorted report would diff against itself between a laptop and a runner.
     */
    public static function forPackage(string $package, string $root): self
    {
        $source = is_dir($root . '/src') ? $root . '/src' : $root;
        $registered = PackageConfiguration::registeredClassNames($root);

        $outcomes = [];
        foreach (RulePaths::expand(is_dir($source) ? [$source] : []) as $file) {
            $name = basename($file, '.php');
            [$verdict, $reason] = self::verdict($file);

            $outcomes[$name] = new RuleOutcome(
                name: $name,
                file: $file,
                verdict: $verdict,
                reason: $reason,
                registered: isset($registered[$name]),
                // No `needs-at-least:` under an unportable one. That list is what a rule's body would take, and this
                // rule's body is not the obstacle — collecting it would invite exactly the sizing the verdict
                // exists to prevent.
                needs: $verdict === RuleOutcome::REFUSE ? self::needs($file) : [],
            );
        }

        ksort($outcomes);

        return new self($package, $root, array_values(self::withSubsumption($outcomes, $source)));
    }

    /**
     * Marks each refused rule whose identifier a rule that emits already reports.
     *
     * A cross-rule pass, and it has to live here rather than in the refusal: the transpiler reads one rule at
     * a time and cannot know that a sibling reports the same identifier. {@see Transpiler::refuseASubsumedCollector()}
     * is the same idea for collectors and works from a static table, which this cannot be — the pairing is a
     * property of whichever package is installed.
     *
     * Under-reports by construction. {@see ReportedIdentifiers} reads literal `->identifier('..')` arguments
     * only, so a rule whose identifier is interpolated or held in a constant contributes none and stays
     * unmarked. That is the safe direction: an unmarked refusal reads as a gap, which is what a refusal
     * already reads as, where a wrongly marked one would say a check is covered when it is not.
     *
     * @param array<string, RuleOutcome> $outcomes keyed by rule name
     * @param string                      $source   the package's source root
     * @return array<string, RuleOutcome>
     */
    private static function withSubsumption(array $outcomes, string $source): array
    {
        // Every PHP file under the package, not the rules. The shared check lives in a trait and the debug
        // rules share a base class, and `RulePaths::expand()` yields neither -- it answers which files are
        // rules, which is a different question. Built from the outcomes first, this pass marked nothing and
        // the census diff was empty, which a green snapshot test reads as "no change" rather than as "the
        // pass never looked".
        $siblings = self::classFilesUnder($source);

        // Keyed by the node type as well as the identifier, and that is the correction rather than a
        // refinement. Comparing identifiers alone marked `ClassMethodCoversExistsRule` as covered by
        // `ClassCoversExistsRule` the moment the latter emitted: both report `phpunit.covers`, one on a
        // method's docblock and one on a class's, so the *name* is shared and the check is not. The six pairs
        // this pass was built for were verified by hand down to their node type, and the pass did not encode
        // that -- so it produced a plausible row the first time a new rule emitted.
        $identifiers = [];
        foreach ($outcomes as $name => $outcome) {
            $type = self::declaredNodeType($outcome->file);
            foreach (ReportedIdentifiers::of($outcome->file, $siblings) as $identifier) {
                $identifiers[$name][] = $type . ' ' . $identifier;
            }
        }

        $emitting = [];
        foreach ($outcomes as $name => $outcome) {
            if (! $outcome->emitted()) {
                continue;
            }

            foreach ($identifiers[$name] ?? [] as $identifier) {
                $emitting[$identifier][] = $name;
            }
        }

        foreach ($outcomes as $name => $outcome) {
            if ($outcome->verdict !== RuleOutcome::REFUSE) {
                continue;
            }

            $covering = [];
            foreach ($identifiers[$name] ?? [] as $identifier) {
                foreach ($emitting[$identifier] ?? [] as $rule) {
                    $covering[$rule] = true;
                }
            }

            if ($covering === []) {
                continue;
            }

            $names = array_keys($covering);
            sort($names);

            $outcomes[$name] = new RuleOutcome(
                name: $outcome->name,
                file: $outcome->file,
                verdict: $outcome->verdict,
                reason: $outcome->reason,
                registered: $outcome->registered,
                needs: $outcome->needs,
                alsoEmittedBy: $names,
            );
        }

        return $outcomes;
    }

    /**
     * The php-parser class a rule declares in `getNodeType()`, or an empty string where it declares none.
     *
     * Read from the source rather than from the transpiler, because a refused rule may refuse before the
     * node type is ever resolved and a marker that only works for emitting rules would compare one side of
     * every pair.
     */
    private static function declaredNodeType(string $file): string
    {
        $source = (string) file_get_contents($file);
        if (preg_match('/function getNodeType\(\)[^{]*\{\s*return ([^;]+);/', $source, $matches) !== 1) {
            return '';
        }

        return trim(str_replace(['::class', '\\'], ['', '\\'], $matches[1]));
    }

    /**
     * Short class name to file for every PHP file under a root.
     *
     * @return array<string, string>
     */
    private static function classFilesUnder(string $source): array
    {
        if (! is_dir($source)) {
            return [];
        }

        $files = [];
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[$entry->getBasename('.php')] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** Every rule class the package ships, wired or not. */
    public function ships(): int
    {
        return count($this->outcomes);
    }

    /** @return list<RuleOutcome> the rules the package wires in a neon of its own */
    public function registered(): array
    {
        return array_values(array_filter($this->outcomes, static fn (RuleOutcome $o): bool => $o->registered));
    }

    /** Registered rules a plugin could carry, which is the denominator a coverage figure quotes. */
    public function portable(): int
    {
        return count($this->registered()) - $this->countVerdict(RuleOutcome::NEVER);
    }

    public function emitted(): int
    {
        return $this->countVerdict(RuleOutcome::EMIT);
    }

    public function refused(): int
    {
        return $this->countVerdict(RuleOutcome::REFUSE);
    }

    public function never(): int
    {
        return $this->countVerdict(RuleOutcome::NEVER);
    }

    /** Rules the package ships and wires nowhere, which no denominator here counts. */
    public function unwired(): int
    {
        return $this->ships() - count($this->registered());
    }

    /** @param RuleOutcome::* $verdict */
    private function countVerdict(string $verdict): int
    {
        return count(array_filter(
            $this->registered(),
            static fn (RuleOutcome $o): bool => $o->verdict === $verdict,
        ));
    }

    /**
     * The verdict and its reason, with line numbers stripped.
     *
     * Only a `Refusal` is caught. A broader catch turned any crash into a refusal whose reason was the crash,
     * which reads as a vocabulary gap and is not one; nothing in the corpus throws anything else today, so
     * letting it fail loudly costs nothing and a bug cannot hide as an outcome.
     *
     * Every line number goes, not only the last: a nested refusal carries the inner construct's line as well
     * as the outer one's, and a construct moving down a file is not drift.
     *
     * @return array{RuleOutcome::*, string|null}
     */
    private static function verdict(string $file): array
    {
        try {
            (new Transpiler($file))->transpile();

            return [RuleOutcome::EMIT, null];
        } catch (Refusal $refusal) {
            $reason = trim((string) preg_replace('/ \(line \d+\)/', '', $refusal->getMessage()));

            return [$refusal->permanent ? RuleOutcome::NEVER : RuleOutcome::REFUSE, $reason];
        }
    }

    /**
     * Everything a refused rule's body needs, rather than only what stops it first.
     *
     * The half work gets sized from. A first blocker says what to fix next; it never says what a fix is
     * worth, and reading it as though it did has been wrong three times here — a type renderer looked like
     * one customer where 27 rules interpolate a rendered type, a five-rule family looked like one missing
     * navigation where it needs that *and* the renderer, and a whole corpus looked absent because the walk
     * that would have read it stopped for an unrelated reason.
     *
     * A lower bound, and the shape of the collection is why: a statement that refuses is stepped over, the
     * statements it encloses are read in its place, and the next one is translated. So obstacles in
     * different statements all appear, and a second obstacle inside one *expression* does not.
     *
     * @return list<string>
     */
    private static function needs(string $file): array
    {
        $survey = Transpiler::$survey;
        Transpiler::$survey = true;

        Transpiler::$collectNeeds = true;

        try {
            $transpiler = new Transpiler($file);

            $terminal = null;

            try {
                $transpiler->transpile();
            } catch (Refusal $refusal) {
                // The verdict is the caller's. The message is kept for the one case below.
                $terminal = $refusal->getMessage();
            }

            $needs = array_map(
                static fn (string $need): string => trim((string) preg_replace('/ \(line \d+\)/', '', $need)),
                $transpiler->needs(),
            );

            // Two artefacts of stepping over a statement, rather than capabilities a rule needs.
            //
            // `unknown local $x` is what a skipped assignment produces: the name is in the source and not in
            // the translated state. Matched anywhere in the line rather than at its start, because the
            // descent into a refusing statement reaches the same name one label deeper — `assignment value
            // outside the vocabulary: unknown local $stmt` is the identical gap with a prefix on it.
            //
            // `outside a loop` is the same thing for a `continue`. A `continue` outside a loop is a fatal
            // error in PHP, so no rule holds one; the message means `inLoop` is false, which after a
            // `foreach` refuses at its iterable it always is. Measured: the phrase appears nowhere in the
            // census this descent was added to, and 28 times in the one it produced.
            $needs = array_filter(
                $needs,
                static fn (string $need): bool => ! str_contains($need, 'unknown local $')
                    && ! str_contains($need, 'outside a loop'),
            );

            // The refusal that *ended* the pass, and only where the pass stepped over nothing.
            //
            // A third artefact of stepping over a statement, and the reason this is conditional rather than
            // unconditional. Whatever finally stops the pass is discarded, so a rule whose body translates
            // and then fails at the end reported an empty list — and an empty list reads as "nothing else
            // needed" rather than as "this cannot be seen from here". `could not find the reported message`
            // is that case, and across the corpus it was the largest first-blocker family after the two
            // vocabulary ones while appearing as a need exactly zero times.
            //
            // Recording it unconditionally is wrong, and measured to be: the message is built by a statement,
            // so any rule with a stepped-over statement reaches the end without one and terminates on the
            // same refusal. That added the label to most refused rules in the corpus and would have inflated
            // the family it exists to size. Where nothing was stepped over, nothing can have removed the
            // message, and the refusal is the rule's own.
            if ($needs === [] && $terminal !== null) {
                $needs = [trim((string) preg_replace('/ \(line \d+\)/', '', $terminal))];
            }

            // First sentence only. A needs entry is a *label* for sizing, and one refusal's full text runs to
            // a paragraph — repeated across the 27 rules that share it, a report would be mostly that
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
}
