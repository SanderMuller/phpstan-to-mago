<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\FloatType;
use Mago\Sdk\Analyzer\Type\FloatTypeKind;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IntegerTypeKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedTruthiness;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ObjectWithMethodType;
use Mago\Sdk\Analyzer\Type\ObjectWithPropertyType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

/**
 * Whether a type is *definitely* truthy or *definitely* falsy, which is PHPStan's `toBoolean()` tails.
 *
 * **This one carries the opposite error direction to every other refusal in this repository, and that is
 * why it is complete rather than careful.** Everywhere else, answering less than PHPStan does makes the port
 * quieter: it refuses, or it fails a `yes` that PHPStan passed, and a missing finding is the safe failure.
 * Here the consumer is `ArrayFilterStrictRule`, which stays quiet **only** when every member of the value
 * union is definitely truthy or definitely falsy and both occur. An undetermined answer makes both flags
 * false, breaks the loop, and *reports* -- so under-detecting definiteness reports where PHPStan is silent.
 * A conservative implementation is the unsafe one, which inverts the usual rule.
 *
 * So the map below is not a plausible set of cases. It is PHPStan's own, derived by enumerating which types
 * use which of its three boolean traits in the installed `phpstan.phar` -- **the phar, which is a different
 * artefact from `phpstan-src`, and the only one installed here.** Recorded because it took a pass to derive
 * and nothing in this repository would re-derive it:
 *
 * | PHPStan (phar)                                                     | verdict     | read here from                       |
 * |:--|:--|:--|
 * | `NullType`, `VoidType` (FalseyBooleanTypeTrait)                    | falsy       | `SimpleAtomicTypeKind::{Null,Void}`  |
 * | `ConstantBooleanType`                                              | its value   | `ScalarType->refinement` as `bool`   |
 * | `ConstantIntegerType`, `ConstantFloatType`, `ConstantStringType`   | `(bool)`    | a `Literal` refinement with a value  |
 * | `IntegerRangeType`                                                 | true if 0 excluded | `IntegerType->{minimum,maximum}` |
 * | `ConstantArrayType` (size)                                         | count > 0   | `nonEmpty`; see the unsafe cell      |
 * | `NonEmptyArrayType`, `AccessoryNonFalsyStringType` (Truthy trait)  | truthy      | `nonEmpty` / `StringType->truthy`    |
 * | every object type, `ClosureType`, `CallableType`, `ResourceType`   | truthy      | the object atomics, and those two    |
 * | `BooleanType`, `IntegerType`, `FloatType`, `StringType` unrefined  | undecided   | a null refinement                    |
 * | `ArrayType`, `IterableType`, `OversizedArrayType`, `StrictMixedType` | undecided | no `nonEmpty`                        |
 * | `NeverType` (Undecided trait)                                      | undecided   | `SimpleAtomicTypeKind::Never`        |
 * | `MixedType`                                                        | subtraction | `MixedTruthiness`, read as the model |
 *
 * Three of those rows are the ones a careful reading gets wrong, so they are named rather than left in the
 * table:
 *
 * - **`ResourceType` and `CallableType` are truthy**, not undecided. Both sit in PHPStan's truthy trait.
 *   Reading either as "cannot tell" is an over-report on a shape that occurs.
 * - **A non-empty string is not a truthy string.** `'0'` is non-empty and falsy, and PHPStan puts
 *   `AccessoryNonEmptyStringType` in the *undecided* trait while `AccessoryNonFalsyStringType` is truthy.
 *   So this reads `StringType->truthy` and never `->nonEmpty`, and `'0'|'x'` is a control fixture.
 * - **`UnspecifiedLiteral` is not a literal.** `IntegerTypeKind::UnspecifiedLiteral` and its float twin mean
 *   "a literal whose value is not known", which may be `0`. Only `Literal` with a value answers.
 *
 * **The unsafe cells, chosen rather than missed.** Five atomics get `null` where PHPStan may determine:
 * `AliasType`, `ReferenceType`, `VariableType` and `DerivedType` expose no target to resolve through, and an
 * empty array shape cannot be told from an un-narrowed `array` -- `KeyedArrayType` carries `nonEmpty` but no
 * emptiness, and asserting a `knownItems === []` spelling without probing it is the inference this repository
 * has been wrong with before. Each is an over-report risk priced by the corpus differential rather than by
 * argument. `GenericParameterType` is *not* among them: every `Template*Type` in the phar extends its bound's
 * class and none declares its own `toBoolean()`, so a template answers exactly as its bound does, and
 * recursing into `constraint` is that.
 */
final class Truthiness
{
    /** The object atomics, all of which PHPStan answers truthy for through `ObjectTypeTrait`. */
    private const array OBJECTS = [
        NamedObjectType::class,
        AnyObjectType::class,
        EnumType::class,
        ObjectShapeType::class,
        ObjectWithMethodType::class,
        ObjectWithPropertyType::class,
    ];

    /**
     * Whether every atomic in `$type` is definitely truthy, which is `->toBoolean()->isTrue()->yes()`.
     *
     * Empty is false rather than vacuously true: PHPStan's `yes` on no atomics is not a `yes`, and a type
     * with no atomics is the absence of an answer rather than agreement.
     */
    public static function typeIsDefinitelyTruthy(?Type $type): bool
    {
        return self::everyAtomicIs($type, true);
    }

    /** Whether every atomic in `$type` is definitely falsy, which is `->toBoolean()->isFalse()->yes()`. */
    public static function typeIsDefinitelyFalsy(?Type $type): bool
    {
        return self::everyAtomicIs($type, false);
    }

    private static function everyAtomicIs(?Type $type, bool $verdict): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (self::atomic($atomic) !== $verdict) {
                return false;
            }
        }

        return true;
    }

    /**
     * One atomic's truthiness: true, false, or null where PHPStan would not decide either.
     *
     * @param AtomicType $atomic the atomic to decide
     */
    private static function atomic(AtomicType $atomic): ?bool
    {
        if (in_array($atomic::class, self::OBJECTS, true)) {
            return true;
        }

        // Both sit in PHPStan's truthy trait. A closed resource is still truthy there -- `ResourceType`
        // carries `closed` and PHPStan has no such distinction, so it is not read.
        if ($atomic instanceof CallableType || $atomic instanceof ResourceType) {
            return true;
        }

        return match (true) {
            $atomic instanceof SimpleAtomicType => self::simple($atomic),
            $atomic instanceof ScalarType => self::scalar($atomic),
            $atomic instanceof KeyedArrayType, $atomic instanceof ListType => $atomic->nonEmpty ? true : null,
            $atomic instanceof MixedType => self::mixed($atomic),
            // A template answers as its bound does, which is inheritance in the phar rather than delegation.
            $atomic instanceof GenericParameterType => self::everyAtomicVerdict($atomic->constraint),
            $atomic instanceof ConditionalType => self::conditional($atomic),
            default => null,
        };
    }

    /** `null` and `void` are PHPStan's only two falsy types; `never` is undecided, not falsy. */
    private static function simple(SimpleAtomicType $atomic): ?bool
    {
        return match ($atomic->kind) {
            SimpleAtomicTypeKind::Null, SimpleAtomicTypeKind::Void => false,
            default => null,
        };
    }

    private static function scalar(ScalarType $atomic): ?bool
    {
        $refinement = $atomic->refinement;

        if (is_bool($refinement)) {
            return $refinement;
        }

        return match (true) {
            $refinement instanceof IntegerType => self::integer($refinement),
            $refinement instanceof StringType => self::string($refinement),
            // `class-string` is always a non-empty, non-`'0'` string, so PHPStan's constant form is truthy
            // and its unrefined form is undecided -- but no class name is `'0'`, so both are truthy here.
            $atomic->kind === ScalarTypeKind::ClassLikeString => true,
            $refinement instanceof FloatType => self::float($refinement),
            default => null,
        };
    }

    /**
     * A literal integer, or a range that excludes zero.
     *
     * `UnspecifiedLiteral` is deliberately not answered: it means the value is a literal and unknown, so it
     * may be `0`. A bound is read only in the direction it constrains -- a `From` of 1 excludes zero, a
     * `From` of -1 does not.
     */
    private static function integer(IntegerType $refinement): ?bool
    {
        if ($refinement->kind === IntegerTypeKind::Literal && $refinement->minimum === $refinement->maximum) {
            return $refinement->minimum !== null ? $refinement->minimum !== 0 : null;
        }

        $excludesZero = ($refinement->minimum !== null && $refinement->minimum > 0)
            || ($refinement->maximum !== null && $refinement->maximum < 0);

        return $excludesZero ? true : null;
    }

    /**
     * A literal string, or one carrying the truthy accessory.
     *
     * `nonEmpty` is never read: `'0'` is non-empty and falsy, and PHPStan agrees -- its non-empty accessory
     * is in the undecided trait and only the non-falsy one is truthy.
     */
    private static function string(StringType $refinement): ?bool
    {
        if ($refinement->truthy) {
            return true;
        }

        if ($refinement->literalKind !== StringLiteralKind::Value || $refinement->literalValue === null) {
            return null;
        }

        return (bool) $refinement->literalValue;
    }

    /** A literal float. `UnspecifiedLiteral` may be `0.0`, so it is not answered. */
    private static function float(FloatType $refinement): ?bool
    {
        if ($refinement->kind !== FloatTypeKind::Literal || $refinement->value === null) {
            return null;
        }

        return (bool) $refinement->value;
    }

    /**
     * `mixed` decides only where a subtraction settled it, which mago carries as a field.
     *
     * Read off `MixedTruthiness` rather than off a rendering: `mixed` and a truthy-narrowed `mixed` both
     * print as `mixed`, which is the projection this repository has already been misled by once.
     */
    private static function mixed(MixedType $atomic): ?bool
    {
        return match ($atomic->truthiness) {
            MixedTruthiness::Truthy => true,
            MixedTruthiness::Falsy => false,
            MixedTruthiness::Undetermined => null,
        };
    }

    /** A conditional decides only where both of its branches agree. */
    private static function conditional(ConditionalType $atomic): ?bool
    {
        $then = self::everyAtomicVerdict($atomic->then);

        return $then !== null && $then === self::everyAtomicVerdict($atomic->otherwise) ? $then : null;
    }

    /** The verdict for a whole `Type`: agreement across its atomics, or null. */
    private static function everyAtomicVerdict(?Type $type): ?bool
    {
        return match (true) {
            self::typeIsDefinitelyTruthy($type) => true,
            self::typeIsDefinitelyFalsy($type) => false,
            default => null,
        };
    }
}
