<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IntegerTypeKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\Truthiness;

/**
 * The truthiness helpers, whose error direction is the inverse of every other refusal in this repository.
 *
 * **Why this is a unit test rather than a fixture.** Everywhere else, answering less than PHPStan does makes
 * the port quieter, and the emitted-output snapshots are the check. Here the consumer
 * (`ArrayFilterStrictRule`) stays quiet **only** when every union member is definitely truthy or definitely
 * falsy and both occur -- so an undetermined answer makes it *report* where PHPStan is silent. Under-detecting
 * is the unsafe direction, and a snapshot cannot see it: the helper is not yet reached by any emitting rule,
 * because that rule still refuses on its `break`. So the rows below are the only thing exercising it.
 *
 * Each test is a control *pair* in the sense this repository means: one row that must answer, and one beside
 * it varying a single axis that must **not**. A row that answers correctly on its own cannot tell a complete
 * implementation from one that got the right answer for the wrong reason.
 *
 * @see Truthiness for the PHPStan trait map these rows are derived from
 */
#[CoversClass(Truthiness::class)]
final class DecidesTruthinessCompletelyOrNotAtAllTest extends TestCase
{
    /**
     * The pair that discriminates the non-empty trap, which is the cell a careful reading gets wrong.
     *
     * `'0'` is a non-empty string and it is falsy. PHPStan agrees -- it puts `AccessoryNonEmptyStringType` in
     * its *undecided* trait and only `AccessoryNonFalsyStringType` in the truthy one. An implementation
     * reading mago's `StringType->nonEmpty` as truthiness answers `true` for `'0'` and passes every other row
     * in this file, so this is the row that catches it.
     */
    public function test_a_non_empty_string_is_not_a_truthy_string(): void
    {
        $this->assertTrue(
            Truthiness::typeIsDefinitelyFalsy(Type::fromAtomic($this->literalString('0'))),
            "`'0'` is non-empty and falsy; reading `nonEmpty` as truthiness is what this row catches.",
        );

        $this->assertTrue(
            Truthiness::typeIsDefinitelyTruthy(Type::fromAtomic($this->literalString('x'))),
            'The control beside it: a non-empty string that really is truthy, so the row above cannot pass '
            . 'by answering "falsy" for every literal string.',
        );
    }

    /**
     * `Foo|null` is the idiom the consumer's quiet path exists for, and it must be decidable both ways.
     *
     * An object is truthy through PHPStan's `ObjectTypeTrait` and `null` is falsy through its falsey trait, so
     * `array_filter($nullableObjects)` is the call the rule deliberately does not report. Getting either half
     * wrong turns that into a finding PHPStan never makes.
     */
    public function test_an_object_is_truthy_and_null_is_falsy(): void
    {
        $object = Type::fromAtomic(new NamedObjectType('Foo', null, null, false, false, null, false));
        $null = Type::fromAtomic(new SimpleAtomicType(SimpleAtomicTypeKind::Null));

        $this->assertTrue(Truthiness::typeIsDefinitelyTruthy($object));
        $this->assertFalse(Truthiness::typeIsDefinitelyFalsy($object));

        $this->assertTrue(Truthiness::typeIsDefinitelyFalsy($null));
        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy($null));
    }

    /**
     * A resource is truthy, which is the row most easily left undecided.
     *
     * `ResourceType` sits in PHPStan's truthy trait. Reading it as "cannot tell" is an over-report, and the
     * control beside it is `never`, which PHPStan really does leave undecided -- so the pair separates
     * "decides both" from "decides neither".
     */
    public function test_a_resource_decides_where_never_does_not(): void
    {
        $resource = Type::fromAtomic(new ResourceType(null));

        $this->assertTrue(Truthiness::typeIsDefinitelyTruthy($resource));

        $never = Type::fromAtomic(new SimpleAtomicType(SimpleAtomicTypeKind::Never));

        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy($never));
        $this->assertFalse(
            Truthiness::typeIsDefinitelyFalsy($never),
            '`never` is in PHPStan\'s *undecided* trait, not its falsey one, so it must answer neither.',
        );
    }

    /**
     * A literal integer decides; a literal whose value is unknown must not.
     *
     * `IntegerTypeKind::UnspecifiedLiteral` means "a literal, value unknown", which may be `0`. Treating it as
     * a literal is how `0` gets answered as truthy. The pair varies only that kind.
     */
    public function test_an_unspecified_literal_is_not_a_literal(): void
    {
        $zero = Type::fromAtomic($this->integer(new IntegerType(IntegerTypeKind::Literal, 0, 0)));
        $one = Type::fromAtomic($this->integer(new IntegerType(IntegerTypeKind::Literal, 1, 1)));

        $this->assertTrue(Truthiness::typeIsDefinitelyFalsy($zero));
        $this->assertTrue(Truthiness::typeIsDefinitelyTruthy($one));

        $unspecified = Type::fromAtomic($this->integer(new IntegerType(IntegerTypeKind::UnspecifiedLiteral)));

        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy($unspecified));
        $this->assertFalse(Truthiness::typeIsDefinitelyFalsy($unspecified));
    }

    /** A range that excludes zero is truthy; one that spans it decides neither. */
    public function test_a_range_decides_only_where_it_excludes_zero(): void
    {
        $positive = Type::fromAtomic($this->integer(new IntegerType(IntegerTypeKind::From, 1)));

        $this->assertTrue(Truthiness::typeIsDefinitelyTruthy($positive));

        $spanning = Type::fromAtomic($this->integer(new IntegerType(IntegerTypeKind::From, -1)));

        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy($spanning));
        $this->assertFalse(Truthiness::typeIsDefinitelyFalsy($spanning));
    }

    /**
     * A union decides only where every member agrees, which is what PHPStan's `yes` means.
     *
     * `int|null` is the shape the consumer reports on: `null` is falsy, a bare `int` is undecided, so neither
     * flag is set. The control is `'0'|''`, where both members really are falsy.
     */
    public function test_a_union_decides_only_where_every_member_agrees(): void
    {
        $bothFalsy = Type::fromAtomics($this->literalString('0'), $this->literalString(''));

        $this->assertTrue(Truthiness::typeIsDefinitelyFalsy($bothFalsy));

        $mixed = Type::fromAtomics(
            $this->integer(new IntegerType(IntegerTypeKind::General)),
            new SimpleAtomicType(SimpleAtomicTypeKind::Null),
        );

        $this->assertFalse(Truthiness::typeIsDefinitelyFalsy($mixed));
        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy($mixed));
    }

    /**
     * A type with no atomics answers neither, rather than answering vacuously.
     *
     * `array_reduce` over no members would otherwise report both flags set, which is the one way this helper
     * could make the consumer *quieter* than PHPStan rather than louder.
     */
    public function test_no_atomics_is_not_agreement(): void
    {
        $this->assertFalse(Truthiness::typeIsDefinitelyTruthy(null));
        $this->assertFalse(Truthiness::typeIsDefinitelyFalsy(null));
    }

    private function literalString(string $value): ScalarType
    {
        return new ScalarType(ScalarTypeKind::String, new StringType(
            StringLiteralKind::Value,
            $value,
            numeric: is_numeric($value),
            truthy: false,
            nonEmpty: $value !== '',
            callable: false,
            casing: StringCasing::Unspecified,
        ));
    }

    private function integer(IntegerType $refinement): ScalarType
    {
        return new ScalarType(ScalarTypeKind::Integer, $refinement);
    }
}
