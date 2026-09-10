<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

/**
 * What a type's atomics are, with no flag and no context in the question.
 *
 * Split out of {@see RuleLevel} once that class sat at 81 against a limit of 80 and {@see Types} at 82, so
 * the next reader of a type had nowhere to live — {@see ArrayTypes} exists as a single-method class for
 * exactly that reason, and folds back in here.
 *
 * The seam is the one this runtime has used since `Support` was 448: every method takes a `Type` and answers
 * a `bool` or another `Type`, none reads a `RuleLevelHelper` flag or a `NodeAnalysisContext`, and none calls
 * back into the flag-aware half. That makes it the transitive closure of one question — *what is this type
 * made of* — while `RuleLevel` keeps the questions PHPStan answers differently per analysis level.
 *
 * A static bag with no shared state, which is the documented condition for a split to take its complexity
 * with it rather than leave two classes over the limit.
 */
final class AtomicShapes
{
    /**
     * The scalar kinds the accepted type `int|float|numeric-string` covers, once mago has dropped the
     * accessory.
     *
     * Public because {@see RuleLevel::keepTheNumbersOf()} filters a union by the same table. Shared rather
     * than duplicated, which is the rule the runtime's earlier splits followed: where two groups genuinely
     * need one thing it moves to the group that owns the question.
     *
     * @var list<ScalarTypeKind>
     */
    public const array NUMERIC = [ScalarTypeKind::Integer, ScalarTypeKind::Float];

    /**
     * Those, plus the one other scalar kind that coerces to a number: `bool`. `null` is not a scalar here.
     *
     * @var list<ScalarTypeKind>
     */
    private const array NUMERIC_OR_COERCIBLE = [ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::Boolean];

    public static function everyAtomicIsString(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::String) {
                return false;
            }
        }

        return $type->atomicTypes !== [];
    }

    /**
     * Whether every part of a type coerces to a number at all — PHPStan's `toNumber()` not answering
     * `ErrorType`.
     *
     * `bool` and `null` coerce and are not numbers, which is the whole population this family reports.
     */
    public static function everyAtomicCoercesToNumber(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            $coerces = $atomic instanceof SimpleAtomicType
                ? $atomic->kind === SimpleAtomicTypeKind::Null
                : $atomic instanceof ScalarType && in_array($atomic->kind, self::NUMERIC_OR_COERCIBLE, true);

            if (! $coerces) {
                return false;
            }
        }

        return $type->atomicTypes !== [];
    }

    public static function everyAtomicIsNumber(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof ScalarType || ! in_array($atomic->kind, self::NUMERIC, true)) {
                return false;
            }
        }

        return $type->atomicTypes !== [];
    }

    public static function isNullOnly(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof SimpleAtomicType || $atomic->kind !== SimpleAtomicTypeKind::Null) {
                return false;
            }
        }

        return true;
    }

    public static function withoutNull(Type $type): Type
    {
        $kept = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            $kept[] = $atomic;
        }

        return Type::fromAtomics(...$kept);
    }

    public static function isBareObject(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof AnyObjectType) {
                return false;
            }
        }

        return true;
    }

    /** Any member `$this` — `findTypeToCheck`'s own short-circuit reads this at levels 0 and 1. */
    public static function isThis(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && $atomic->isThis) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every atomic of the type is an array, which is `count($type->getArrays()) > 0` in PHPStan.
     *
     * The name says *wholly*, and that is the measured semantics rather than the obvious reading.
     * `getArrays()` looks like "the array atomics within this type", which would make the test "any atomic is
     * an array". It is not. Probed against PHPStan directly:
     *
     * | PHPStan type                              | `count(getArrays())` |
     * |:------------------------------------------|---------------------:|
     * | `array`                                   | 1                    |
     * | `non-empty-array`                         | 1                    |
     * | `list`                                    | 1                    |
     * | a constant array shape                    | 1                    |
     * | the empty array                           | 1                    |
     * | `array<int,string>|array<string,string>`  | 1                    |
     * | `array|int`                               | **0**                |
     * | `array|bool`                              | **0**                |
     *
     * A union carrying a non-array member answers zero, so the question is whether the type is an array and
     * nothing else — every atomic, the same shape {@see RuleLevel} asks of strings and numbers.
     *
     * Mago splits arrays over two atomics, `KeyedArrayType` and `ListType`, which {@see Describe} already
     * renders as `array` and `list<..>`. Both count.
     *
     * ## Inert in `OperandsInArithmeticAdditionRule`, and the port keeps it anyway
     *
     * The only rule that asks declines when *both* operands are arrays, because `array + array` is a valid
     * union. That guard cannot change what the rule reports, and it was proved twice rather than argued:
     *
     * - Every one of the eight types above with a non-zero count has an `ErrorType` from `toNumber()`, and
     *   `OperatorRuleHelper::isValidForArithmeticOperation()` returns *valid* two branches earlier for
     *   exactly that. Zero counterexamples. So an array operand never reports, and declining when both are
     *   arrays removes a finding that was never going to be made.
     * - Removing the guard from PHPStan's own copy of the rule left its findings byte-identical over a
     *   twelve-row fixture whose other rows do report — a positive control in the same run.
     *
     * Kept because it is the question the original asks, and folding it away would make this port depend on
     * `isValidForArithmeticOperation()` keeping its `toNumber()` gate. That gate is upstream's to change, and
     * a version bump has already broken one assumption of this repository's about that file.
     */
    public static function typeIsWhollyArray(?Type $type): bool
    {
        if (! $type instanceof Type) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof KeyedArrayType && ! $atomic instanceof ListType) {
                return false;
            }
        }

        return $type->atomicTypes !== [];
    }

    /** Any member `mixed`, which every caller here reads as "do not decide". */
    public static function isMixed(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value type of an iterable, which is `Type::getIterableValueType()`.
     *
     * Mago spells it three ways depending on the atomic -- a keyed array carries `valueType`, a list carries
     * `elementType`, an `IterableType` carries `valueType` -- so the union across atomics is what corresponds
     * to PHPStan's single answer. `Type::fromAtomics()` is the public factory; the constructor is private, so
     * a composed answer has to go through it.
     *
     * Union rather than the first atomic found. `array<int>|array<string>` has value type `int|string` in
     * PHPStan, and answering `int` would be narrower than the rule -- and narrower here means *reporting*
     * where the original is quiet, because a caller testing the answer for a union shape would find a scalar
     * instead and fall through to its report.
     *
     * A type with no iterable atomic answers null, which is how these helpers spell "no answer". PHPStan
     * returns a `never` there and every predicate asked of a `never` is false, which a null gives too.
     */
    public static function iterableValueType(?Type $type): ?Type
    {
        if (! $type instanceof Type) {
            return null;
        }

        $atomics = [];
        foreach ($type->atomicTypes as $atomic) {
            $value = match (true) {
                $atomic instanceof KeyedArrayType => $atomic->valueType,
                $atomic instanceof ListType => $atomic->elementType,
                $atomic instanceof IterableType => $atomic->valueType,
                default => null,
            };

            if ($value instanceof Type) {
                foreach ($value->atomicTypes as $inner) {
                    $atomics[] = $inner;
                }
            }
        }

        if ($atomics === []) {
            return null;
        }

        $first = array_shift($atomics);

        return Type::fromAtomics($first, ...$atomics);
    }

    /**
     * A union's member types, which is `UnionType::getTypes()`.
     *
     * One `Type` per atomic, through `Type::fromAtomic()`, because that is the shape a rule asks questions
     * of: `foreach ($itemType->getTypes() as $inner) { $inner->toBoolean() }` wants a type per member and
     * not an atomic. A non-union answers its single member rather than nothing -- PHPStan only reaches
     * `getTypes()` behind an `instanceof UnionType`, so the one-member answer is unreachable from a rule and
     * is the honest reading of the list rather than a special case.
     *
     * @return list<Type>
     */
    public static function unionMembers(?Type $type): array
    {
        if (! $type instanceof Type) {
            return [];
        }

        $members = [];
        foreach ($type->atomicTypes as $atomic) {
            $members[] = Type::fromAtomic($atomic, $type->flags);
        }

        return $members;
    }
}
