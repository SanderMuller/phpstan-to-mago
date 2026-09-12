<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\PackageCoverage;
use Sandermuller\PhpstanToMago\RuleOutcome;
use Sandermuller\PhpstanToMago\Tests\Support\LockedCorpus;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A refusal says how far its `needs-at-least:` list falls short, and one that falls short by nothing says so.
 *
 * `PackageCoverage::needs()` drops three labels as artefacts of stepping over a statement. Where they are
 * dropped they stand for work the pass cannot see: an unbound loop variable makes every statement in the
 * body refuse with `unknown local $x`, so a rule whose whole body sits inside one loop prints one visible
 * need and hides the rest. The third is `a second message before the first was reported`, which a
 * stepped-over conditional report provokes in a rule whose two reports are both portable.
 *
 * **This exists because the floor was read as a total three times in one session.** Ranking the refused
 * rules by reason frequency, then by blocker count, then by sole-terminal-need each produced a candidate
 * described as one capability away, and each was wrong. The count is what separates those readings, so it is
 * asserted in both directions rather than merely printed.
 *
 * @see MarksARefusalAlreadyCoveredTest for the same both-directions discipline on a different census field
 */
#[CoversClass(PackageCoverage::class)]
#[CoversClass(RuleOutcome::class)]
final class SaysHowFarTheNeedsListFallsShortTest extends TestCase
{
    /** @var array<string, RuleOutcome>|null */
    private static ?array $strict = null;

    /**
     * The row that made the field necessary.
     *
     * `MatchingTypeInSwitchCaseConditionRule` refuses at `foreach ($node->cases as $case)`. The body is
     * descended into -- `Transpiler::bodyOf()` reads what a refusing statement encloses -- but `$case` was
     * never bound, so every statement inside refuses with `unknown local $case` and is filtered. Measured
     * with the filter off: three raw needs, two suppressed, one printed. The rule wants five capabilities.
     */
    public function test_a_rule_whose_loop_body_was_hidden_reports_the_shortfall(): void
    {
        $outcome = $this->outcome('MatchingTypeInSwitchCaseConditionRule');

        $this->assertSame(RuleOutcome::REFUSE, $outcome->verdict, 'This row is stale: the rule now emits.');
        $this->assertGreaterThan(
            0,
            $outcome->suppressedNeeds,
            'The needs list is printed without a shortfall, so a reader sizing this rule sees one blocker '
            . 'where the loop body refused on an unbound local and was filtered.',
        );
    }

    /**
     * The control, and it must not move -- **with needs of its own**, which is what makes it a control.
     *
     * The first version of this test used `ClassAttributeRequiresPhpVersionRule`, whose sole need is
     * `could not find the reported message`. That need is injected *after* the filter, so the rule's raw
     * count is zero and the shortfall is zero under any formula that subtracts or counts. Mutating the field
     * to `$before` -- the raw total, which measures the walk rather than what the walk could not see -- left
     * that row passing. A control that passes for the wrong reason looks exactly like a control.
     *
     * `UselessCastRule` discriminates: four needs, none suppressed. Under the `$before` mutation this row
     * reads four.
     *
     * **Zero suppressed is not zero hidden**, and this docblock said it was. `UselessCastRule` steps over
     * four statements without producing a single artefact label, so its obstacles are visible only in the
     * sense that no *filter* dropped one. `$steppedOver` is the field that carries the rest, and the row
     * below asserts it, so the pair cannot be read as "this rule is nearly done" again.
     */
    public function test_a_rule_that_hid_nothing_reports_no_shortfall(): void
    {
        $outcome = $this->outcome('UselessCastRule');

        $this->assertSame(RuleOutcome::REFUSE, $outcome->verdict, 'This row is stale: the rule now emits.');
        $this->assertNotSame(
            [],
            $outcome->needs,
            'The control has no needs of its own, so a field that counted the raw total rather than the '
            . 'suppressed part would satisfy it at zero and the pair would not discriminate.',
        );
        $this->assertSame(
            0,
            $outcome->suppressedNeeds,
            'A rule that had no refusal filtered reports a suppressed count, so the count is measuring the '
            . 'walk rather than what the filter dropped.',
        );

        $this->assertGreaterThan(
            0,
            $outcome->steppedOver,
            'This rule steps over four statements and the census would print no qualifier at all, which is '
            . 'the reading that sent a session to one of the largest rules in the corpus.',
        );
    }

    /**
     * The one rule whose body translated whole, which is what a zero here has to mean.
     *
     * `ClassAttributeRequiresPhpVersionRule` steps over nothing: its only need is the terminal refusal, and
     * {@see PackageCoverage::needs()} records that one *only* where nothing was stepped over. So this is the
     * row that gives the field its floor, and a change making every rule report a step-over breaks it.
     */
    public function test_a_body_that_translated_whole_steps_over_nothing(): void
    {
        $outcome = $this->outcome('ClassAttributeRequiresPhpVersionRule', 'phpstan/phpstan-phpunit');

        $this->assertSame(RuleOutcome::REFUSE, $outcome->verdict, 'This row is stale: the rule now emits.');
        $this->assertSame(0, $outcome->steppedOver);
        $this->assertSame(0, $outcome->suppressedNeeds);
    }

    private function outcome(string $rule, string $package = 'phpstan/phpstan-strict-rules'): RuleOutcome
    {
        // A `--prefer-lowest` leg installs older rule packages whose rules differ, so a row naming a rule
        // that does not exist there is a fact about the corpus rather than a stale assertion. The same guard
        // `ReportsInstalledCoverageTest` and the census assertion use, honouring the same deliberate-drift
        // escape.
        $mismatch = LockedCorpus::mismatch();
        if ($mismatch !== null) {
            self::markTestSkipped($mismatch);
        }

        $outcomes = $this->outcomes($package);
        if (! isset($outcomes[$rule])) {
            self::fail("{$rule} is no longer in the package, so this row is stale.");
        }

        return $outcomes[$rule];
    }

    /** @return array<string, RuleOutcome> */
    private function outcomes(string $package): array
    {
        if ($package === 'phpstan/phpstan-strict-rules' && self::$strict !== null) {
            return self::$strict;
        }

        // The census's own target, so a verdict does not depend on which test ran before this one --
        // `Transpiler::$target` and `$survey` are static. `TracksUpstreamDriftTest` pins them for the same
        // reason, and `MarksARefusalAlreadyCoveredTest` was written twice for want of it.
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $keyed = [];
        foreach (PackageCoverage::forPackage(
            $package,
            dirname(__DIR__, 2) . '/vendor/' . $package,
        )->outcomes as $outcome) {
            $keyed[$outcome->name] = $outcome;
        }

        if ($package === 'phpstan/phpstan-strict-rules') {
            self::$strict = $keyed;
        }

        return $keyed;
    }
}
