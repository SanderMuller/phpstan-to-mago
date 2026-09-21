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
     *
     * **Re-probed at each bump, and the answer is recorded here so a later reader can tell a checked claim
     * from a carried one.** The two look identical in a diff that only moves the version string.
     *
     * | version | `Describe::list()`'s explicit-mixed claim |
     * |:--|:--|
     * | 1.48.1  | holds — `MixedType` carries `issetFromLoop`, `nonNull`, `empty`, `truthiness` |
     * | 1.49.0  | holds — same four fields, and `explicit` appears nowhere in the SDK |
     * | 1.50.0  | holds — unchanged again, by the same two checks |
     *
     * The 1.49.0 row was read off the installed tree and confirmed against the released source at the tag,
     * searching for the *capability* rather than for a field name: a separator could have arrived under any
     * spelling, so the check is that nothing in `Sdk/` mentions an explicit mixed at all.
     */
    private const string MEASURED_AGAINST = '1.50.0';

    /**
     * Only a *newer* mago needs a re-probe, which is what makes this survive `--prefer-lowest`.
     *
     * The first version asserted equality and was wrong in a way that stayed hidden while the constant and
     * the `composer.json` floor happened to be the same number. They diverged the moment a claim was probed
     * at 1.49.0 while the floor correctly stayed at `^1.48.1` -- the emitted plugins need 1.48.1 and nothing
     * newer -- and the lowest leg then failed reporting that "mago moved from 1.49.0 to 1.48.1". That leg
     * installs the declared floor on purpose; it is the constraint working, not drift.
     *
     * **An absence claim verified at version N holds for every version below it**, because a capability that
     * is missing at N was missing earlier too -- engines add them going forward. So an older install cannot
     * falsify one of these sentences and has nothing to re-probe.
     *
     * The converse, a capability being *removed* upstream, would pass this silently. That is deliberate and
     * cheap to justify: nothing here claims a capability is present except the code that calls it, and a
     * removal breaks that loudly -- `Runtime\Definedness` would fatal on a missing enum case the way every
     * emitted plugin did on 1.47.6, which is a failure no alarm has to predict.
     */
    public function test_an_engine_blaming_refusal_is_rechecked_when_mago_moves(): void
    {
        $installed = $this->installedMago();

        $this->assertFalse(
            version_compare($installed, self::MEASURED_AGAINST, '>'),
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

    /**
     * The control pair for the direction, which is the part the `--prefer-lowest` leg got wrong.
     *
     * One row that must alarm and one beside it that must not, varying only which side of the pin the
     * installed version falls. An assertion that fired on any difference passed the first and failed the
     * second, which is exactly what shipped.
     */
    public function test_it_alarms_only_upward(): void
    {
        // Derived from the constant rather than written as a literal. The first version of this row said
        // `1.50.0`, which was newer right up until the constant was bumped to 1.50.0 and the row began
        // asserting that a version alarms against itself. A control pinned to a literal expires silently the
        // next time the thing it controls for moves.
        [$major, $minor] = array_map(intval(...), explode('.', self::MEASURED_AGAINST) + [1 => '0']);
        $newer = $major . '.' . ($minor + 1) . '.0';

        $this->assertTrue(
            version_compare($newer, self::MEASURED_AGAINST, '>'),
            "A newer mago ({$newer}) must alarm.",
        );

        $this->assertFalse(
            version_compare($this->declaredFloor(), self::MEASURED_AGAINST, '>'),
            'The declared floor must not alarm: `--prefer-lowest` installs it deliberately, and an absence '
            . 'claim verified higher up already covers it.',
        );

        $this->assertFalse(
            version_compare(self::MEASURED_AGAINST, self::MEASURED_AGAINST, '>'),
            'The probed version itself must not alarm.',
        );
    }

    /**
     * The floor `composer.json` declares, which is what `--prefer-lowest` installs.
     *
     * Read rather than repeated: this row exists because the floor and the probed version are allowed to
     * differ, so hard-coding either one here would re-create the coupling the row is testing for.
     */
    private function declaredFloor(): string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertIsArray($manifest);
        $dev = $manifest['require-dev'] ?? [];
        self::assertIsArray($dev);
        $constraint = $dev['carthage-software/mago'] ?? '';
        self::assertIsString($constraint);
        self::assertSame(1, preg_match('/(\d+\.\d+\.\d+)/', $constraint, $match));

        return $match[1];
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
