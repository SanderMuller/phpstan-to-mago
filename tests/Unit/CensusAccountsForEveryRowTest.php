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
                . "counted:  %s  = %d\n"
                . "stated:   %d\n\n"
                . "every leading uppercase run in the file, as a diagnostic (GENERATED is the header and\n"
                . "a bare letter is prose, so neither belongs in the sum):\n  %s\n\n"
                . "A residual means a verdict nobody counted — add it to VERDICTS and re-read whatever\n"
                . "audit called itself complete. An excess means one of these is not a rule row. And a\n"
                . "corpus that gained or lost a rule needs the header literal in TracksUpstreamDriftTest\n"
                . 'updated, since it is hand-written and nothing else checks it.',
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
