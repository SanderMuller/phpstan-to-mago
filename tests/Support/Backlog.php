<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * The refused rules in the order they are worth working on, read off the census.
 *
 * Printed by `php tests/Support/run-backlog.php`, which is a wrapper over this.
 *
 * **This exists because the ordering was not an artefact of this repository and so it was improvised.** In
 * one session the backlog was ranked five times -- by refusal-reason frequency, by blocker count, by
 * sole-terminal-need, by "no floor", and by fewest needs -- each in a shell one-liner, each giving a
 * different answer, and four of the five named a candidate that dissolved on inspection. Every signal those
 * rankings needed was already in the census. None of them used the same subset twice.
 *
 * The axes, in the order they decide:
 *
 * 1. **Marginal coverage.** A refusal marked `also-emitted-by:` has none: the check it performs already ships
 *    through a sibling that emits, so porting it adds no finding anyone would see and adds a duplicate-report
 *    risk if a consumer registers both. Six such rules were worked toward for most of a session on the
 *    strength of their low blocker counts; `also-emitted-by` had the answer the whole time.
 *
 *    **Currently redundant, and kept anyway with the measurement beside it.** Every rule carrying
 *    `also-emitted-by` today also refuses on an unwirable constructor parameter, so axis 2 alone already
 *    sorts all six last: removing this axis moves no row. That is a fact about this corpus rather than about
 *    the ordering, and the axis decides the *reason printed on the row*, which axis 2 cannot -- a reader
 *    told "a stop, not a cost" would go looking for the value nobody can supply, where the operative fact is
 *    that the check already ships.
 * 2. **Whether the obstacle is work at all.** A constructor parameter the package's neon does not wire, or a
 *    container parameter whose value is a function of which extensions the analysed project installs, is a
 *    stop rather than a cost. No amount of vocabulary reaches it.
 * 3. **How much of the rule was actually read.** `floor:` counts the statements the survey stepped over. The
 *    needs list is deduplicated and cannot reach inside an expression, so a rule with two needs and five
 *    step-overs is not half the work of one with four needs and none.
 * 4. **The needs themselves**, last, because they are the signal that is least informative on its own.
 *
 * **What this cannot see, stated rather than left to be discovered.** It cannot see what a rule is *for*.
 * A rule with no sibling, no stop and no step-overs may still fire nothing on real code, and one that fires
 * nothing is worth nothing however cheap it is. {@see run-refusal-yield.php} answers that and needs a corpus;
 * this needs only the census. Read both before committing to a rule -- the yield instrument has already
 * overturned an ordering this one would produce.
 */
/**
 * One refused rule, typed so the ordering below reads as a comparison rather than as offset arithmetic.
 */
final class Backlog
{
    /** @return list<BacklogRow> the refused rules, best first */
    public static function rows(): array
    {
        $census = dirname(__DIR__) . '/Fixtures/expected/census.md';
        if (! is_file($census)) {
            throw new \RuntimeException("no census at {$census}");
        }

        /** @var list<BacklogRow> $rows */
        $rows = [];
        $current = null;

        foreach (explode("\n", (string) file_get_contents($census)) as $line) {
            if (preg_match('/^(EMIT|REFUSE|NEVER)\s+(\S+)/', $line, $match) === 1) {
                $current = null;
                if ($match[1] === 'REFUSE') {
                    $rows[] = new BacklogRow($match[2]);
                    $current = count($rows) - 1;
                }

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^\s+needs-at-least: (.*)$/', $line, $match) === 1) {
                $rows[$current]->needs[] = $match[1];
            }

            if (preg_match('/^\s+floor: (\d+) statement/', $line, $match) === 1) {
                $rows[$current]->stepped = (int) $match[1];
            }

            if (preg_match('/^\s+also-emitted-by: (.*)$/', $line, $match) === 1) {
                $rows[$current]->covered = $match[1];
            }
        }

        usort($rows, static fn (BacklogRow $a, BacklogRow $b): int => $a->rank() <=> $b->rank());

        return $rows;
    }

    public static function render(): string
    {
        $rows = self::rows();

        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, strlen($row->name));
        }

        $out = "\n  " . count($rows) . " refused rules, best first. Marginal coverage decides, then whether the\n"
            . "  obstacle is work at all, then how much of the rule was read, then the needs.\n\n";

        foreach ($rows as $row) {
            $out .= sprintf("  %-{$width}s  %s\n", $row->name, $row->reason());
        }

        return $out . "\n  Ordering is not worth. A rule that fires nothing on real code is worth nothing however\n"
            . "  cheap: run tests/Support/run-refusal-yield.php over a corpus before committing to any row.\n";
    }
}
