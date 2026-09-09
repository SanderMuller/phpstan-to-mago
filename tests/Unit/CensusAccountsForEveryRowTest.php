<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every rule row in the census belongs to a verdict, and the verdicts sum to the total the header states.
 *
 * The census has more row kinds than any one reading of it attends to. A triage of the refused rules was
 * recorded as "43 of 43, complete" while eight `NEVER` rows sat four lines away in the same file, unread —
 * no access problem and not even a different artefact: **the subset being attended to was smaller than the
 * file being read, and nothing in the file said so.**
 *
 * The first countermeasure proposed for that was `grep -oE '^[A-Z]+ ' | sort | uniq -c`, and a peer ran it
 * and found it matches the census's own prose — *"A refused rule also lists…"* satisfies `^[A-Z]+ ` — so it
 * reported six kinds against four real ones with nothing in the output distinguishing them. A pattern loose
 * enough to admit `A` is equally able to miss a kind spelled some other way.
 *
 * **The defect was that a listing has no expected value, and an instrument with no expected value cannot
 * fail.** That is the same property that let "43 of 43" read as complete.
 *
 * So the check is a sum against an anchor the file already carries, and the kind listing is the diagnostic
 * rather than the check. It fails in both directions: a kind nobody counted leaves a residual, and a false
 * kind produces an excess.
 *
 * The anchor is load-bearing for a second reason. That total is a hand-written literal in the header prose
 * of {@see TracksUpstreamDriftTest}, not a figure derived from the rows — so without this it can go stale
 * silently the moment the corpus gains or loses a rule, which is the carried-figure hazard `CLAUDE.md`
 * records and the one thing a generated file cannot catch about its own header.
 */
final class CensusAccountsForEveryRowTest extends TestCase
{
    private const string CENSUS = __DIR__ . '/../Fixtures/expected/census.md';

    /** The verdicts a rule row can carry. A new one added to `RuleOutcome` and not to this list fails below. */
    private const array VERDICTS = ['EMIT', 'REFUSE', 'NEVER', 'ENGINE'];

    /**
     * The two emit counts this project publishes reconcile, and the bridge is the annotation.
     *
     * `grep -c '^EMIT'` on the census answers **140** and `--status` answers **130**, and both are correct
     * for their denominator: 140 rules emit, and 130 emit *and* are registered by the package that ships
     * them. The difference is exactly the rows carrying `(the package registers it nowhere)` — a rule a
     * consumer never runs, because nothing wires it.
     *
     * **Nothing stated that.** The larger figure is in this log and the census, the smaller in the README and
     * `--status`, and a reader comparing them saw a ten-rule discrepancy with no way to tell which was
     * wrong. A count with no independent total is unfalsifiable; a *pair* of published counts with no stated
     * bridge is worse, because it looks like one of them is a mistake.
     *
     * Asserted rather than explained, for the reason the sum above is: prose stating the relationship would
     * not fail when a new annotated rule starts emitting and only one of the two figures moves.
     */
    public function test_the_two_emit_counts_reconcile_through_the_annotation(): void
    {
        $census = (string) file_get_contents(self::CENSUS);

        // Cast for the reason the method below states: `preg_match_all` answers `int|false`, and a false
        // folded into this arithmetic would make the two sides agree by accident.
        $emitting = (int) preg_match_all('/^EMIT /m', $census);
        $unregistered = (int) preg_match_all('/^EMIT .*\(the package registers it nowhere\)/m', $census);

        // Each package header states how many of the rules it *registers* emit. That is the figure `--status`
        // reports and the one a consumer's own run reproduces.
        preg_match_all('/^## \S+ — (\d+) of \d+ portable rules/m', $census, $perPackage);
        $registered = array_sum(array_map(intval(...), $perPackage[1]));

        $this->assertNotSame(0, $unregistered, 'No EMIT row carries the annotation, so this asserts nothing.');
        $this->assertNotSame([], $perPackage[1], 'No package header was parsed, so this asserts nothing.');

        $this->assertSame(
            $emitting - $unregistered,
            $registered,
            sprintf(
                "The census's two emit counts no longer reconcile.\n\n"
                . "  EMIT rows                                     %d\n"
                . "  of those, annotated 'registers it nowhere'    %d\n"
                . "  difference                                    %d\n"
                . "  summed from the per-package headers           %d\n\n"
                . 'The second is what `--status` reports and what the README quotes. If they have parted, one '
                . 'of the two is being computed over a population the other is not, and whichever figure '
                . 'a reader compares against will look like the wrong one.',
                $emitting,
                $unregistered,
                $emitting - $unregistered,
                $registered,
            ),
        );
    }

    public function test_the_verdicts_sum_to_the_total_the_header_states(): void
    {
        $census = (string) file_get_contents(self::CENSUS);

        $this->assertSame(
            1,
            preg_match("/against this file's (\d+)/", $census, $stated),
            'The census header no longer states its own rule total, which is the anchor this asserts against.',
        );

        $counts = [];
        foreach (self::VERDICTS as $verdict) {
            // Cast because `preg_match_all` answers `int|false`, and a false would otherwise be summed as 0
            // -- a silent zero for a verdict that exists is the failure this whole test is about.
            $counts[$verdict] = (int) preg_match_all('/^' . $verdict . ' /m', $census);
        }

        // The kind listing is the diagnostic, not the check — printed only when the sum disagrees, and
        // including every leading uppercase run so a verdict this test does not know about is visible.
        $seen = [];
        preg_match_all('/^([A-Z]+) /m', $census, $kinds);
        foreach ($kinds[1] as $kind) {
            $seen[$kind] = ($seen[$kind] ?? 0) + 1;
        }

        $this->assertSame(
            (int) $stated[1],
            array_sum($counts),
            sprintf(
                "The census's verdict rows no longer sum to the total its header states.\n\n"
                . "  derived from the rows:   %s  = %d\n"
                . "  hand-written in the header prose (TracksUpstreamDriftTest): %d\n\n"
                . "Which side moved decides what to do, and the two are not symmetric — one is generated\n"
                . "from this file's own rows and the other is a literal somebody typed.\n\n"
                . "  a residual  the rows carry a verdict nobody counted. Add it to VERDICTS, then re-read\n"
                . "              whatever audit called itself complete without it.\n"
                . "  an excess   one of the counted kinds is not a rule row.\n"
                . "  either      the corpus gained or lost a rule, and the hand-written literal is stale.\n\n"
                . "every leading uppercase run in the file, as a diagnostic rather than a check (GENERATED\n"
                . "is the header and a bare letter is prose, so neither belongs in the sum):\n  %s\n",
                implode(' + ', array_map(
                    static fn (string $k, int $n): string => "{$k} {$n}",
                    array_keys($counts),
                    $counts,
                )),
                array_sum($counts),
                (int) $stated[1],
                implode(', ', array_map(
                    static fn (string $k, int $n): string => "{$k}={$n}",
                    array_keys($seen),
                    $seen,
                )),
            ),
        );
    }
}
