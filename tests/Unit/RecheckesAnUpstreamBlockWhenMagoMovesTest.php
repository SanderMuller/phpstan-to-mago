<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A refusal that blames the engine is re-checked when the engine moves.
 *
 * **This exists because a pessimistic claim is self-sealing.** An optimistic one is caught by the work
 * failing: you build against it, the gate refuses, the differential disagrees, and the claim dies with output
 * attached. Every other guard in this repository works that way. A claim that something is *blocked* stops
 * the work that would falsify it, so it produces no gate to fail and no differential to disagree -- nobody
 * probes a capability their own docblock says is absent.
 *
 * One did survive that way. `Translator::definednessTest()` refused the PHP target on
 * `carthage-software/mago#2334`, and the issue had been closed as completed for three days: the API shipped,
 * name-keyed, and three rules worth 147 findings sat behind a sentence that was true when written. Nothing in
 * this repository could have noticed. What noticed was a closure date sitting beside a pinned version and not
 * lining up.
 *
 * So the trigger is mechanical rather than remembered: the version the claim was measured against, asserted
 * against the version actually installed. A bump makes this fail and names what to re-run. That is the only
 * check that reaches a claim which suppresses its own evidence.
 *
 * @see Translator::definednessTest() for the claim, the probe and the map it produced
 */
#[CoversNothing]
final class RecheckesAnUpstreamBlockWhenMagoMovesTest extends TestCase
{
    /**
     * The mago the definedness refusal was measured against.
     *
     * Probed on this version: a `Foreach` hook requesting every type requirement gets no type at the loop
     * variable, so the refusal held. The capability that lifts it is name-keyed and arrived after this
     * release.
     */
    private const string MEASURED_AGAINST = '1.47.6';

    public function test_the_definedness_block_is_rechecked_when_mago_moves(): void
    {
        $installed = $this->installedMago();

        $this->assertSame(
            self::MEASURED_AGAINST,
            $installed,
            'mago moved from ' . self::MEASURED_AGAINST . " to {$installed}, and this repository carries a "
            . 'refusal measured against the older one. `Translator::definednessTest()` refuses the PHP target '
            . 'on carthage-software/mago#2334, which is closed as completed: '
            . '`FileAnalysisRequirement::VariableDefinedness` and '
            . '`NodeAnalysisContext::getVariableDefinedness(string)` are implemented and were unreleased at '
            . self::MEASURED_AGAINST . '. Re-probe before trusting that refusal: it blocks '
            . 'OverwriteVariablesWithForeachRule, DisallowedImplicitArrayCreationRule and '
            . 'OverwriteVariablesWithForLoopInitRule, worth 147 findings on the corpus this tool measures. '
            . 'If the capability is now present, update the refusal and this constant together.',
        );
    }

    private function installedMago(): string
    {
        $lock = dirname(__DIR__, 2) . '/composer.lock';
        $decoded = json_decode((string) file_get_contents($lock), true);

        self::assertIsArray($decoded);

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $decoded[$section] ?? [];
            if (! is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'carthage-software/mago') {
                    return is_string($package['version'] ?? null) ? $package['version'] : '(unreadable)';
                }
            }
        }

        return '(absent)';
    }
}
