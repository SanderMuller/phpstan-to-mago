<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Analyzer\Type;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\RuleLevel;

/**
 * The ported `passesAsBoolean` against the behaviour real PHPStan was measured to have.
 *
 * Every row here was read off a real PHPStan run over a fixture before it was written down, at the three
 * flag combinations the corpora and the gate use. A control table written from the source alone would
 * assert what the code appears to do; these assert what it was seen to do.
 *
 * The nullable rows are the ones worth the file. `?bool` is silent at `checkNullables: false` and reports
 * at true, which is exactly what separates hihaho from Shopware -- so a port that got them backwards would
 * agree with one corpus and be wrong on the other, and only one of the two differentials would say so.
 */
final class PortsRuleLevelHelperTest extends TestCase
{
    public function test_a_boolean_passes(): void
    {
        $this->assertTrue(RuleLevel::passesAsBoolean(Type::bool(), false, false, false));
        $this->assertTrue(RuleLevel::passesAsBoolean(Type::bool(), true, true, false));
    }

    public function test_a_string_does_not_pass(): void
    {
        $this->assertFalse(RuleLevel::passesAsBoolean(Type::string(), false, false, false));
        $this->assertFalse(RuleLevel::passesAsBoolean(Type::string(), true, true, false));
    }

    /**
     * The discriminator between the two corpora, and the reason the flags cannot be baked.
     */
    public function test_a_nullable_boolean_turns_on_check_nullables(): void
    {
        $nullableBool = Type::union(Type::bool(), Type::null());

        $this->assertTrue(RuleLevel::passesAsBoolean($nullableBool, false, true, false), 'hihaho, level 7');
        $this->assertFalse(RuleLevel::passesAsBoolean($nullableBool, true, true, false), 'shopware, level 8');
    }

    /**
     * A union whose members all fail is *not* narrowed away.
     *
     * `count($newTypes) > 0` upstream is what makes this fall through to the original type. Without it
     * every all-failing union would be silenced, which is the population the rule exists to report.
     */
    public function test_a_union_that_fails_entirely_still_reports(): void
    {
        $intOrString = Type::union(Type::int(), Type::string());

        $this->assertFalse(RuleLevel::passesAsBoolean($intOrString, false, false, false));
        $this->assertFalse(RuleLevel::passesAsBoolean($intOrString, false, true, false));
    }

    /**
     * A union with one passing member is filtered down to it, but only where the flag says to filter.
     */
    public function test_a_partly_boolean_union_turns_on_check_union_types(): void
    {
        $boolOrInt = Type::union(Type::bool(), Type::int());

        $this->assertTrue(RuleLevel::passesAsBoolean($boolOrInt, false, false, false), 'levels 0-6, filtered');
        $this->assertFalse(RuleLevel::passesAsBoolean($boolOrInt, false, true, false), 'levels 7+, unfiltered');
    }

    /**
     * Mixed passes, which is a stated divergence rather than a translation.
     *
     * PHPStan answers `!$type->isExplicitMixed()`. Mago's `MixedType` carries no explicit flag, so the port
     * picks the branch that under-reports rather than over-reports.
     */
    public function test_mixed_passes_because_mago_cannot_tell_the_two_apart(): void
    {
        $this->assertTrue(RuleLevel::passesAsBoolean(Type::mixed(), true, true, false));
    }

    /**
     * `checkThisOnly` defaults true and turns off at level 2, so it silences the family at levels 0 and 1.
     *
     * Found by running the example pairs at level 0 and getting nothing back. It reads as one more dead
     * flag next to the mixed branches, and it is the opposite: live exactly where the gate runs.
     */
    public function test_check_this_only_silences_a_subject_that_is_not_this(): void
    {
        $this->assertTrue(RuleLevel::passesAsBoolean(Type::string(), true, true, true), 'levels 0-1');
        $this->assertFalse(RuleLevel::passesAsBoolean(Type::string(), true, true, false), 'levels 2+');
    }

    public function test_an_absent_type_says_nothing(): void
    {
        $this->assertTrue(RuleLevel::passesAsBoolean(null, true, true, false));
    }
}
