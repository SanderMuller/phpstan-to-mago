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
 * **The mechanism has now fired once, which is the only evidence that it works.**
 * `Translator::definednessTest()` refused the PHP target on `carthage-software/mago#2334`, and the issue had
 * been closed as completed for three days: the API shipped, name-keyed, and three rules sat behind a sentence
 * that was true when written. Nothing else in this repository could have noticed -- the emit diff saw no
 * change, the census recorded the refusal as settled, and the suite was green. This test failed on the bump
 * to 1.48.1, named the capability and the rules, and the port was built the same day. It is kept, repointed,
 * rather than retired with the claim it caught.
 *
 * So the trigger is mechanical rather than remembered: the version the claims were measured against,
 * asserted against the version actually installed. A bump makes this fail and names what to re-run.
 *
 * @see Runtime\Describe::list() for the claim this now guards
 * @see Runtime\Definedness for the one it caught, and the port that replaced it
 */
#[CoversNothing]
final class RecheckesAnUpstreamBlockWhenMagoMovesTest extends TestCase
{
    /**
     * The mago the live claims below were measured against.
     *
     * Bump this only with the re-probe done, never to make the suite green: the whole point is that nothing
     * else here can notice when an engine-blaming sentence stops being true.
     */
    private const string MEASURED_AGAINST = '1.48.1';

    public function test_an_engine_blaming_refusal_is_rechecked_when_mago_moves(): void
    {
        $installed = $this->installedMago();

        $this->assertSame(
            self::MEASURED_AGAINST,
            $installed,
            'mago moved from ' . self::MEASURED_AGAINST . " to {$installed}, and this repository carries "
            . 'claims about what the engine cannot do that were measured against the older one. Re-probe each '
            . 'before trusting it:'
            . "\n\n  Runtime\\Describe::list() drops a `list<mixed>` parameter because `MixedType` carries "
            . '`issetFromLoop`, `nonNull`, `empty` and `truthiness` and nothing separating an *explicit* mixed '
            . 'from an inferred one, where PHPStan branches on `isExplicitMixed()`. A new field there closes '
            . 'the gap and makes the current behaviour a trade that no longer needs making.'
            . "\n\nIf a capability is now present, update the claim and this constant together. This test has "
            . 'already caught one such sentence: the definedness refusal, lifted at 1.48.1.',
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
