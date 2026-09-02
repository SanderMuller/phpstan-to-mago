<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Analyzer\Type;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\RuleLevel;

/**
 * The ported `isValidForArithmeticOperation` against the behaviour real PHPStan was measured to have.
 *
 * The sibling of {@see PortsRuleLevelHelperTest}, and written the same way round: every row was read off a
 * real PHPStan run before it was written down. One file held one unary `+` per operand shape, and
 * `OperandInArithmeticUnaryPlusRule` was run over it at each flag combination — the same run
 * `internal/probe-arithmetic-atomics.php` names, whose mago half says which atomics arrive.
 *
 * This file earns its place where the example pair cannot reach. The fires gate runs at the level-0
 * defaults, so `checkNullables` and `checkUnionTypes` are both false there and the two rows that turn on
 * them are untested by any pair. Those are also the rows a reader would most likely get backwards: `?int` is
 * silent until *both* flags are on, because the null is stripped before the union is looked at.
 */
final class PortsTheArithmeticOperandHelperTest extends TestCase
{
    public function test_a_number_is_a_valid_operand(): void
    {
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::int(), false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::float(), false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::int(), true, true, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::float(), true, true, false));
    }

    /** The whole population the family reports: coerces to a number, and is not one. */
    public function test_a_boolean_and_a_null_are_not(): void
    {
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::bool(), false, false, false));
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::null(), false, false, false));
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::bool(), true, true, false));
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::null(), true, true, false));

        // A literal `true` reports as well, and the real run names it `true given`. Its atomic is a boolean
        // scalar carrying a refinement, so it needs no branch of its own — asserted because that is a fact
        // about mago's model rather than about this function.
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::true(), false, false, false));
    }

    /**
     * Everything PHPStan core already reports on passes here, which is the original's own comment.
     *
     * `toNumber()` answers `ErrorType` for all of these, and that branch returns *true*. A port that read it
     * as a failure would report a string, an array and every object shape where PHPStan says nothing.
     */
    public function test_an_operand_that_cannot_coerce_at_all_is_left_to_phpstan_core(): void
    {
        $uncoercible = [
            'string' => Type::string(),
            'numeric-string, which mago renders as a bare string' => Type::literalString('12'),
            'array' => Type::array(Type::int(), Type::string()),
            'a named object' => Type::namedObject('Acme\\Money'),
            'a bare object' => Type::object(),
        ];

        foreach ($uncoercible as $shape => $type) {
            $this->assertTrue(RuleLevel::isValidForArithmeticOperation($type, false, false, false), $shape);
            $this->assertTrue(RuleLevel::isValidForArithmeticOperation($type, true, true, false), $shape);
        }
    }

    /** Mago's `MixedType` carries no explicit flag, so the port passes both kinds. */
    public function test_mixed_passes(): void
    {
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::mixed(), false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::mixed(), true, true, false));
    }

    /** A union of coercible members is checked only where the flag says to check one. */
    public function test_a_partly_numeric_union_turns_on_check_union_types(): void
    {
        $intOrBool = Type::union(Type::int(), Type::bool());

        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($intOrBool, false, false, false), 'levels 0-6');
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation($intOrBool, false, true, false), 'levels 7+');
    }

    /**
     * A nullable number needs both flags, and the order they apply in is why.
     *
     * With `checkNullables` off the null is stripped and the `int` that remains is a valid operand, so the
     * union flag has nothing to look at. With it on, the type stays `int|null` and the union flag decides.
     */
    public function test_a_nullable_number_needs_both_flags(): void
    {
        $nullableInt = Type::union(Type::int(), Type::null());

        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($nullableInt, false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($nullableInt, false, true, false), 'null stripped');
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($nullableInt, true, false, false), 'union not checked');
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation($nullableInt, true, true, false));
    }

    /**
     * A union holding one uncoercible member is silent whatever the flags say.
     *
     * `int|string` is the measured case, and it separates this port from the boolean one: there the same
     * union reports, because a string is a perfectly good thing to *fail* a boolean test with. Here the
     * string means the whole type cannot coerce, which is PHPStan core's finding rather than this rule's.
     */
    public function test_a_union_with_an_uncoercible_member_says_nothing(): void
    {
        $intOrString = Type::union(Type::int(), Type::string());

        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($intOrString, false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($intOrString, true, true, false));
    }

    /**
     * `checkThisOnly` silences the family, which is what the gate's own configuration works around.
     *
     * Measured on the real run: with the flag at its level-0 default the rule reports nothing at all, so a
     * pair written without turning it off would have both tools agreeing on zero.
     */
    public function test_check_this_only_silences_a_subject_that_is_not_this(): void
    {
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(Type::bool(), false, false, true));
        $this->assertFalse(RuleLevel::isValidForArithmeticOperation(Type::bool(), false, false, false));
    }

    /** A type the read could not answer reports nothing, the direction every helper here defaults to. */
    public function test_an_absent_type_says_nothing(): void
    {
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation(null, false, false, false));
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(null, false, false, false));
    }

    /**
     * The increment family is much wider than the arithmetic one, and this is the row that says so.
     *
     * `isValidForIncrement()` and `isValidForDecrement()` have no `toNumber()` pass, so an `array` and a
     * named object are this rule's own findings rather than PHPStan core's. Reusing the arithmetic answer
     * would have silenced the largest part of the population.
     */
    public function test_an_array_and_an_object_report_for_an_increment_and_not_for_an_addition(): void
    {
        $array = Type::array(Type::int(), Type::string());
        $object = Type::namedObject('Acme\\Money');

        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement($array, false, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement($object, false, false, false));

        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($array, false, false, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($object, false, false, false));
    }

    /** A bare `object` follows `checkUnionTypes`, where a named one reports either way. */
    public function test_a_bare_object_follows_check_union_types_for_an_increment(): void
    {
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(Type::object(), false, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement(Type::object(), false, true, false));
    }

    /** `bool` and `null` report at every setting here as well. */
    public function test_a_boolean_and_a_null_are_not_valid_increment_operands(): void
    {
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement(Type::bool(), false, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement(Type::null(), false, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement(Type::bool(), true, true, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement(Type::null(), true, true, false));
    }

    /**
     * `int|string` reports for an increment and never for an addition, and the union flag gates it.
     *
     * The pair of rows that separates the two ports most sharply: there the string makes the whole type
     * uncoercible and PHPStan core owns it, here there is no such branch.
     */
    public function test_a_union_with_a_string_member_turns_on_check_union_types_for_an_increment(): void
    {
        $intOrString = Type::union(Type::int(), Type::string());

        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement($intOrString, false, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement($intOrString, false, true, false));
        $this->assertTrue(RuleLevel::isValidForArithmeticOperation($intOrString, false, true, false));
    }

    /**
     * The chosen divergence: a plain string passes, because mago cannot tell it from a numeric one.
     *
     * PHPStan reports `--$text` and says nothing about `--$numeric`. Both arrive as the same atomic here, so
     * one answer has to serve both, and passing is the direction that under-reports rather than reporting
     * something the original allows.
     */
    public function test_a_string_passes_because_mago_cannot_tell_a_numeric_one_apart(): void
    {
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(Type::string(), false, false, false));
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(Type::string(), true, true, false));
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(Type::literalString('12'), true, true, false));
    }

    /** `?int` needs both flags here too, and for the same reason. */
    public function test_a_nullable_number_needs_both_flags_for_an_increment(): void
    {
        $nullableInt = Type::union(Type::int(), Type::null());

        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement($nullableInt, false, false, false));
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement($nullableInt, false, true, false));
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement($nullableInt, true, false, false));
        $this->assertFalse(RuleLevel::isValidForIncrementOrDecrement($nullableInt, true, true, false));
    }

    /** And `checkThisOnly` silences this family the same way. */
    public function test_check_this_only_silences_an_increment_as_well(): void
    {
        $this->assertTrue(RuleLevel::isValidForIncrementOrDecrement(Type::bool(), false, false, true));
    }
}
