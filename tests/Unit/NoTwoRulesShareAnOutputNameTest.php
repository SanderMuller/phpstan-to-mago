<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Two rules of one class name emit to one file, and the transpiler refuses both when it sees them together.
 *
 * That refusal is correct — *refuse rather than approximate*, applied to an output path. What it does to a
 * harness is the reason this test exists. Every emit-all comparison in one session passed
 * `vendor/symplify/phpstan-rules/src` and `tests/Fixtures/Rules` on **one command line**, and both hold a
 * rule named `UppercaseConstantRule`. So that rule emitted into *neither* side of any byte diff taken. The
 * diff stayed valid — both trees equally short — and the per-target counts matched each other, so nothing
 * announced it. A change affecting only that rule would have been invisible to every comparison made.
 *
 * **A correct, loudly-refusing component was misused into silence by the thing measuring it**, which is
 * worse than a broken component for the same reason a green byte diff on a runtime change is worse than a
 * regex that matches the wrong rows: nothing was wrong anywhere. The refusal was right, the diff was valid,
 * the counts agreed, and the defect was entirely in what the harness asked.
 *
 * Discharged rather than noted: symplify's rule was emitted on its own at that session's first and last
 * commits and its output is byte-identical, so the blind spot cost nothing — but only a measurement could
 * say so.
 *
 * This fails when a new collision appears, which a vendor update can introduce without anyone touching this
 * repository. Then either emit the colliding corpora into separate trees or exclude one path, and never
 * trust matching counts from a single invocation to say the trees are complete.
 */
final class NoTwoRulesShareAnOutputNameTest extends TestCase
{
    /** The paths an emit-all run walks, which is where a collision can hide. */
    private const array PATHS = [
        'vendor/symplify/phpstan-rules/src',
        'vendor/hihaho/phpstan-rules/src',
        'vendor/tomasvotruba/type-coverage/src',
        'vendor/tomasvotruba/cognitive-complexity/src',
        'vendor/phpstan/phpstan-strict-rules/src',
        'vendor/phpstan/phpstan-phpunit/src',
        'vendor/phpstan/phpstan-deprecation-rules/src',
        'tests/Fixtures/Rules',
    ];

    public function test_no_two_rule_classes_across_the_emit_all_paths_share_a_name(): void
    {
        $root = dirname(__DIR__, 2);

        /** @var array<string, list<string>> $declared */
        $declared = [];

        /** @var array<string, int> $perPath rule classes found under each configured path */
        $perPath = [];

        foreach (self::PATHS as $path) {
            $absolute = $root . '/' . $path;
            if (! is_dir($absolute)) {
                continue;
            }

            $perPath[$path] = 0;

            foreach ($this->phpFilesUnder($absolute) as $file) {
                $source = (string) file_get_contents($file);

                // Anchored, and only a *rule*: a support class of a shared name emits nothing, so it cannot
                // collide. `Configuration`, `RuleIdentifier` and `ShouldNotHappenException` are each declared
                // twice across these paths and none of them is a rule.
                if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w*Rule)\b/m', $source, $name) !== 1) {
                    continue;
                }

                $declared[$name[1]][] = str_replace($root . '/', '', $file);
                ++$perPath[$path];
            }
        }

        $this->assertNotSame([], $declared, 'No rule classes were found, so this is asserting nothing.');

        // The reject side, asserted rather than printed. A filter's rejections produce no row, no count and
        // no trace, so an instrument that silently narrows returns a clean, smaller, confident answer — which
        // is what the first version of this test did: a `/[Tt]ests?/` exclusion meant for a vendor package's
        // own test directory swallowed `tests/Fixtures/Rules` whole, and the test passed green having read
        // one of the two colliding files.
        //
        // A path contributing zero rule classes is either misconfigured or excluded by a pattern that was
        // not meant to reach it. Either way the scan is smaller than it claims and nothing else would say so.
        $empty = array_keys(array_filter($perPath, static fn (int $found): bool => $found === 0));

        $this->assertSame(
            [],
            $empty,
            "These configured paths contributed no rule classes, so the scan is narrower than it claims:\n  "
            . implode("\n  ", $empty)
            . "\n\nEither the path is wrong or a filter in this test excluded it. The first version of this "
            . 'test excluded `tests/Fixtures/Rules` for having "tests" in its path and passed green, which is '
            . 'why this assertion exists: a rejection is the only outcome that leaves no evidence it '
            . "happened.\n\nFound per path: "
            . json_encode($perPath),
        );

        $collisions = array_filter($declared, static fn (array $files): bool => count($files) > 1);

        // The one known pair, listed rather than renamed. Renaming the fixture would move three reviewed
        // snapshots, an examples directory and five test references to protect a harness that does not
        // exist in this repository — the emit-all comparison is an ad-hoc invocation, and the fix for it is
        // to pass the colliding paths separately. Measured before accepting: symplify's rule emitted on its
        // own at the first and last commit of the session that found this, and its output is byte-identical,
        // so the blind spot cost nothing.
        //
        // Listed in code so a *new* collision fails. An accepted-collision comment would have no expected
        // value and could not.
        unset($collisions['UppercaseConstantRule']);

        $report = '';
        foreach ($collisions as $class => $files) {
            $report .= "\n  {$class}\n      " . implode("\n      ", $files);
        }

        $this->assertSame(
            [],
            $collisions,
            'These rule classes share a name across the emit-all paths, so each pair emits to one file and '
            . "the transpiler refuses both when it sees them together:{$report}\n\n"
            . 'Emit the colliding corpora into separate trees, or exclude one path — and do not trust '
            . 'matching per-target counts from a single invocation to say the trees are complete, because a '
            . "collision leaves both sides equally short and they will agree.\n\n"
            . 'If the new pair is deliberate, add it beside `UppercaseConstantRule` above with the same '
            . 'measurement: emit each side on its own and compare, so the accepted cost is known rather '
            . 'than assumed.',
        );
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            // A vendor package's own test directory holds rules that are fixtures for *its* suite and never
            // emit here. `tests/Fixtures/Rules` is this repository's corpus and must not be skipped — a
            // `/[Tt]ests?/` guard excludes it, which made the first version of this test pass by never
            // looking at the half of the input where the known collision lives. The instrument failed the
            // way the thing it measures failed.
            if (str_contains($entry->getPathname(), '/vendor/') && preg_match('#/[Tt]ests?/#', $entry->getPathname()) === 1) {
                continue;
            }

            $files[] = $entry->getPathname();
        }

        return $files;
    }
}
