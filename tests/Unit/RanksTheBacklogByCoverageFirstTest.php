<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\Backlog;
use Sandermuller\PhpstanToMago\Tests\Support\BacklogRow;

/**
 * The backlog is ordered by marginal coverage before blocker count, and the order is asserted.
 *
 * **The ordering used to be improvised, which is why this is a test rather than a convention.** In one
 * session the refused rules were ranked five times -- by refusal-reason frequency, by blocker count, by
 * sole-terminal-need, by "no floor", and by fewest needs -- each in a shell one-liner, each giving a
 * different answer, and four named a candidate that dissolved on inspection. Every signal was already in the
 * census. A committed script gives the same answer twice; a one-liner does not.
 *
 * Two rows are asserted and they are the two the improvised orderings got wrong:
 *
 * - A rule whose check already ships through an emitting sibling sorts **last**, whatever its blocker count.
 *   Six of those were worked toward for most of a session because they showed one blocker each.
 * - A rule whose every obstacle is a value nobody can supply sorts after real work and before those, because
 *   it is a stop rather than a cost.
 *
 * @see run-refusal-yield.php for the axis this one cannot see -- what a rule is *for*, which needs a corpus
 */
#[CoversNothing]
final class RanksTheBacklogByCoverageFirstTest extends TestCase
{
    /** @var list<string>|null */
    private static ?array $order = null;

    /**
     * The *reason* on the row, which is the part the coverage axis alone decides.
     *
     * The position is not: every rule carrying `also-emitted-by` today also refuses on an unwirable
     * constructor parameter, so the stop axis already sorts all six last and removing the coverage axis moves
     * no row — mutation-checked, both axes dropped in turn, and the order held either way. Asserting position
     * would have been a control passing for the wrong reason.
     *
     * The reason is load-bearing. Without the coverage axis the row reads "a stop, not a cost", which sends a
     * reader looking for a value nobody can supply when the operative fact is that the check already ships
     * through a sibling and porting it would add a duplicate rather than a finding.
     */
    public function test_a_rule_a_sibling_already_covers_says_so_rather_than_naming_its_parameter(): void
    {
        $ranking = $this->ranking();

        $this->assertMatchesRegularExpression(
            '/NoUnsafeRequestDataRule\s+no marginal coverage — already emitted by CombinedMethodCallRule/',
            $ranking,
            'A refusal whose check already emits through a sibling is reported by its parameter instead, '
            . 'which is true and is not the reason not to work on it.',
        );

        $this->assertGreaterThan(
            array_search('AssertSameWithCountRule', $this->order(), true),
            array_search('NoUnsafeRequestDataRule', $this->order(), true),
            'A refusal that is not work outranks one that is.',
        );
    }

    /**
     * The same discipline for the stop axis: assert the reason, not the position.
     *
     * `VariablePropertyFetchRule` steps over five statements, so it sorts after real work whether or not the
     * stop axis exists — mutation-checked, and asserting position passed with `isStop()` hard-wired to
     * false. The reason is what that axis alone decides, and it is the part a reader acts on: told "1 need,
     * 5 stepped over" they would go looking for a capability, where the refusal is that the value is a
     * function of which extensions the analysed project installs and no plugin can read it.
     */
    public function test_a_value_nobody_can_supply_says_it_is_a_stop_rather_than_a_count(): void
    {
        $this->assertContains(
            'VariablePropertyFetchRule',
            $this->order(),
            'This row is stale: the rule is no longer refused.',
        );

        $this->assertMatchesRegularExpression(
            '/VariablePropertyFetchRule\s+a stop, not a cost/',
            $this->ranking(),
            'A refusal naming a value nobody can supply is reported as a count of needs, so a reader sizing '
            . 'the backlog sees work where there is no cost to pay.',
        );
    }

    /**
     * Measured yield outranks the structural axes, and the row says the count.
     *
     * The script's closing line has always said that a rule firing nothing is worth nothing however cheap,
     * and until now the ordering could not act on it: `ClassAttributeRequiresPhpVersionRule` sat first on
     * zero findings because it steps over nothing. With a `run-refusal-yield.php` report passed as
     * `PTM_YIELD`, the rules that actually fire lead.
     *
     * Yield is optional and passed as a path rather than committed, because a count belongs to the corpus it
     * was measured on. So this asserts against a fixture report rather than a real corpus run, which also
     * keeps the test off a ten-minute measurement.
     */
    public function test_measured_yield_outranks_a_cheaper_rule_that_fires_nothing(): void
    {
        $report = tempnam(sys_get_temp_dir(), 'ptm-yield-');
        file_put_contents($report, "  findings  refused rule\n"
            . "       259  UselessCastRule                                cast.useless\n"
            . "         0  ClassAttributeRequiresPhpVersionRule           phpunit.attr\n");

        try {
            $order = array_map(
                static fn (BacklogRow $row): string => $row->name,
                Backlog::rows($report),
            );
            $rendered = Backlog::render($report);
        } finally {
            unlink($report);
        }

        $this->assertLessThan(
            array_search('ClassAttributeRequiresPhpVersionRule', $order, true),
            array_search('UselessCastRule', $order, true),
            'A rule firing 259 times ranks below one firing nothing, so the ordering is still structural '
            . 'where a measurement was supplied.',
        );

        $this->assertStringContainsString('259 finding(s)', $rendered);
    }

    public function test_the_report_says_what_it_cannot_see(): void
    {
        $this->assertStringContainsString(
            'run-refusal-yield.php',
            $this->ranking(),
            'The ranking is printed without the instrument that measures what a rule is for, and a cheap '
            . 'rule that fires nothing is worth nothing.',
        );
    }

    /** @return list<string> the rule names, in the order the script prints them */
    private function order(): array
    {
        if (self::$order !== null) {
            return self::$order;
        }

        return self::$order = array_map(static fn (BacklogRow $row): string => $row->name, Backlog::rows());
    }

    private function ranking(): string
    {
        return Backlog::render();
    }
}
