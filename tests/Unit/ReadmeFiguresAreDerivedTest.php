<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every figure in the README that has a source is checked against that source.
 *
 * The README was the only shipped document with no test, and it showed: an audit found five stale figures
 * and one that was false — *"the two smallest trees carry most of the divergences"*, where the two smallest
 * carry 7 of 31 and the tree with 22 of them is mid-sized. Nothing would have caught either, because a
 * figure copied into prose has no relationship to the file it came from once it is written.
 *
 * This is the *"a number that cannot be re-derived should not be in the document"* rule made enforceable.
 * The measurements are exempt and stay exempt: a benchmark row is a reading, not a derivation, and asserting
 * it would only pin the machine it was taken on.
 *
 * @see CensusAccountsForEveryRowTest for the same discipline applied
 *      to the census's own internal figures
 */
final class ReadmeFiguresAreDerivedTest extends TestCase
{
    private const string README = __DIR__ . '/../../README.md';

    private const string CENSUS = __DIR__ . '/../Fixtures/expected/census.md';

    private const string SWEEP = __DIR__ . '/../Fixtures/expected/corpus-sweep.md';

    private const string DIVERGENCES = __DIR__ . '/../Fixtures/expected/divergences.md';

    public function test_the_per_package_table_matches_the_census(): void
    {
        $census = (string) file_get_contents(self::CENSUS);
        $readme = (string) file_get_contents(self::README);

        preg_match_all(
            '/^## (\S+) — (\d+) of (\d+) portable rules the package registers emit, (\d+) covered by the '
            . 'engine, (\d+) refuse/m',
            $census,
            $rows,
            PREG_SET_ORDER,
        );

        $this->assertNotSame([], $rows, 'No package heading was parsed from the census.');

        foreach ($rows as $row) {
            [, $package, $emit, $portable, $engine, $refused] = $row;

            $expected = sprintf(
                '| `%s` | %d | %d | %d | %d |',
                $package,
                (int) $portable,
                (int) $emit,
                (int) $refused,
                (int) $engine,
            );

            $this->assertStringContainsString(
                $expected,
                $readme,
                "The README's row for {$package} does not match the census, which now reads:\n  {$expected}",
            );
        }
    }

    public function test_the_status_figure_matches_the_census(): void
    {
        $census = (string) file_get_contents(self::CENSUS);

        // What `--status` reports: rules that emit *and* are registered by the package shipping them.
        // {@see CensusAccountsForEveryRowTest} asserts that this equals the summed package headings.
        $registered = (int) preg_match_all('/^EMIT /m', $census)
            - (int) preg_match_all('/^EMIT .*\(the package registers it nowhere\)/m', $census);

        // The denominator counts packages the census does not read, so it cannot be derived from the rows.
        // It is read off the census header instead of repeated here: it used to be a literal in this test
        // *and* a literal in the generated header, and sixteen rules arriving upstream made both wrong at
        // once while only one of them was anywhere near a failing assertion. One stated figure, two readers.
        $this->assertSame(
            1,
            preg_match('/`--status` counts (\d+) portable rules here/', $census, $total),
            'The census header no longer states the `--status` denominator, which this reads rather than '
            . 'repeats.',
        );

        $this->assertStringContainsString(
            "`--status` counts {$registered} of {$total[1]} here",
            (string) file_get_contents(self::README),
            "The README's --status figure does not match the census: the registered-and-emitting count is "
            . "{$registered} and the census header states a denominator of {$total[1]}.",
        );
    }

    public function test_the_corpus_sweep_figures_match_the_sweep(): void
    {
        $sweep = (string) file_get_contents(self::SWEEP);

        preg_match_all('/agree (\d+), only-original (\d+), only-port (\d+)/', $sweep, $rows, PREG_SET_ORDER);
        $this->assertNotSame([], $rows, 'No sweep rows were parsed.');

        $agree = array_sum(array_map(static fn (array $r): int => (int) $r[1], $rows));
        $diverge = array_sum(array_map(static fn (array $r): int => (int) $r[2] + (int) $r[3], $rows));

        $readme = (string) file_get_contents(self::README);
        $this->assertStringContainsString("**{$agree} agreeing against {$diverge} divergences**", $readme);

        // The claim about size, which is the one an audit found false. Re-derived, not restated: the tree
        // with the most divergences and the largest tree with none.
        preg_match_all(
            '/^## (\S+)\s+(\d+) files\s+agree \d+, only-original (\d+), only-port (\d+)/m',
            $sweep,
            $trees,
            PREG_SET_ORDER,
        );

        $worst = null;
        $cleanest = null;
        foreach ($trees as $tree) {
            [, , $files, $original, $port] = $tree;
            $count = (int) $original + (int) $port;
            if ($worst === null || $count > $worst[1]) {
                $worst = [(int) $files, $count];
            }

            if ($count === 0 && ($cleanest === null || (int) $files > $cleanest)) {
                $cleanest = (int) $files;
            }
        }

        $this->assertNotNull($worst);
        $this->assertNotNull($cleanest);
        $this->assertStringContainsString("{$cleanest} files of PHPUnit carry no divergence", $readme);
        $this->assertStringContainsString(
            "{$worst[0]} files of Laravel's\nsupport and database trees carry {$worst[1]} of the {$diverge}",
            $readme,
            'The README names the tree with the most divergences and the largest clean one. One of those has '
            . "moved: worst is now {$worst[1]} divergences over {$worst[0]} files, cleanest is {$cleanest} files.",
        );
    }

    public function test_the_pinned_divergence_counts_match_the_record(): void
    {
        preg_match_all(
            '/^## (\S+)\s+(AGREE|DIVERGE)/m',
            (string) file_get_contents(self::DIVERGENCES),
            $cases,
            PREG_SET_ORDER,
        );

        $this->assertNotSame([], $cases, 'No pinned cases were parsed.');

        $total = count($cases);
        $closed = count(array_filter($cases, static fn (array $c): bool => $c[2] === 'AGREE'));

        $this->assertStringContainsString(
            "seven so far, {$this->spell($closed)} of which have since closed",
            (string) file_get_contents(self::README),
            "The record now holds {$total} pinned cases, {$closed} of them closed.",
        );

        // The word "seven" above is the total, spelled out. Asserted separately so a new case fails here
        // rather than passing on a substring that happens to still match.
        $this->assertSame(7, $total, 'A pinned case was added or removed; the README says seven.');
    }

    public function test_the_requirements_match_composer_json(): void
    {
        /** @var array{require: array<string, string>, require-dev: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Mago is a dev dependency here — this package transpiles *to* it and does not require it at
        // runtime — but a generated plugin needs it, which is the constraint the README states.
        $readme = (string) file_get_contents(self::README);
        $php = ltrim($composer['require']['php'], '^~>= ');
        $mago = ltrim($composer['require-dev']['carthage-software/mago'], '^~>= ');

        $this->assertStringContainsString("PHP {$php}", $readme);
        $this->assertStringContainsString("Mago {$mago} or later", $readme);
    }

    private function spell(int $n): string
    {
        return ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven'][$n] ?? (string) $n;
    }
}
