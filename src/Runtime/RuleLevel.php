<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

/**
 * PHPStan's `RuleLevelHelper` semantics, ported rather than translated.
 *
 * A rule that asks "is this operand a boolean" does not ask its own question: it asks
 * `BooleanRuleHelper::passesAsBoolean()`, which asks `RuleLevelHelper::findTypeToCheck()`. That function
 * takes a *criteria closure*, and a closure over PHPStan `Type` objects is not something this transpiler
 * can translate. So the port is one level out, at the helpers that each hardcode their callback, which is
 * what makes them the smallest portable unit.
 *
 * Ported for the arithmetic and boolean-condition families, whose rules refuse for no other reason.
 *
 * ## The flags are the behaviour, so they are arguments
 *
 * `findTypeToCheck` reads six container parameters and behaves differently under each. They are not on the
 * level ladder in the way a reader expects: all six default false, four turn on one per level from 7 to 10,
 * and `checkBenevolentUnionTypes` appears in no level config at all. A consumer may also set any of them
 * directly, which a level-to-flag table would read wrongly.
 *
 * They are therefore parameters here and constructor parameters on the emitted plugin, never baked. That is
 * forced rather than chosen: measured on the two corpora, hihaho runs `checkNullables: false` and Shopware
 * `true`, and a `?bool` condition is silent under the first and reports under the second. One baked set
 * cannot agree with both.
 *
 * ## What is deliberately not ported, and why each one is safe
 *
 * - **The class-existence branch.** It returns `ErrorType`, which both consumers read as *pass*, so it is a
 *   silencer rather than an error emitter and omitting it would convert silence into a report. Measured
 *   across 10,844 operand positions on two frameworks at two levels: it never engages in either family's
 *   position. Dead by measurement, so re-measure it when the corpus changes.
 * - **The explicit- and implicit-mixed branches inside `findTypeToCheck`.** Reachable only at levels 9 and
 *   10. Dead by configuration.
 * - **The object-with-no-class-names branch.** Reachable only where `checkUnionTypes` is false, which is
 *   levels 6 and below. Dead by configuration -- and *not* dead in general: `phpstan-strict-rules`
 *   registers through its own config, so a consumer can run these families at level 5.
 *
 * ## Two divergences, both stated rather than discovered
 *
 * - **Mixed passes.** `passesAsBoolean` opens with `if ($type instanceof MixedType) return
 *   !$type->isExplicitMixed();`, outside `findTypeToCheck` and live at every level. Mago's `MixedType`
 *   carries `issetFromLoop`, `nonNull`, `empty` and `truthiness` and no explicit flag, so the distinction
 *   cannot be made. Passing matches implicit mixed and under-reports explicit, which surfaces as
 *   `only-original` -- the direction to choose when one must be chosen.
 * - **Benevolent unions are invisible.** Mago has no counterpart: "benevolent" appears nowhere in the SDK
 *   and `TypeFlags` carries eleven flags, none of them it. PHPStan is *lenient* on them, so a port that
 *   cannot see them is strict where PHPStan is quiet -- the unsafe direction. Measured before accepting:
 *   **zero** benevolent operands in condition position on either corpus, so this does not touch
 *   `passesAsBoolean` at all. The arithmetic position has two divergent sites in total, both the same
 *   idiom, and they are named where that helper is added -- not here.
 */
final class RuleLevel
{
    /**
     * Whether an expression's type passes as a boolean, the way `BooleanRuleHelper` decides it.
     */
    public static function passesAsBoolean(
        ?Type $type,
        bool $checkNullables,
        bool $checkUnionTypes,
        bool $checkThisOnly,
    ): bool {
        if (! $type instanceof Type) {
            return true;
        }

        // `checkThisOnly` is the flag easiest to read as harmless, and it is not. It defaults *true* and
        // turns off at level 2, so at levels 0 and 1 `findTypeToCheck` short-circuits to `ErrorType` for any
        // subject that is not `$this`, which silences this whole family. Found by running the example pairs
        // at level 0 and getting nothing back: the gate runs there, so without this both sides would have
        // agreed on zero, which is the one result the gate exists to refuse.
        //
        // The subject is `$this` exactly where mago marks the atomic as such, rather than where its name
        // happens to match the enclosing class.
        if ($checkThisOnly && ! self::isThis($type)) {
            return true;
        }

        if (self::isMixed($type)) {
            return true;
        }

        $found = self::findTypeToCheck($type, $checkNullables, $checkUnionTypes);

        return $found instanceof Type && Types::typeIsBoolean($found);
    }

    /**
     * The type `findTypeToCheck` narrows to, or null where it answers `ErrorType`.
     *
     * `ErrorType` is not an error to the callers: both read it as *pass*, so null here means "say nothing".
     */
    private static function findTypeToCheck(Type $type, bool $checkNullables, bool $checkUnionTypes): ?Type
    {
        if (! $checkNullables) {
            $type = self::withoutNull($type);
            if (! $type instanceof Type) {
                return null;
            }
        }

        if (self::isMixed($type)) {
            return null;
        }

        return self::filterUnion($type, $checkUnionTypes);
    }

    /**
     * Members failing the criteria are dropped, but only where the flag says to drop them.
     *
     * The `count($newTypes) > 0` guard upstream is why an all-failing union is *not* narrowed to nothing:
     * it falls through and the original type is checked, so `int|string` reports while `bool|int` passes.
     * Dropping that guard would silence every union whose members all fail, which is the whole population
     * the rule exists to report.
     */
    private static function filterUnion(Type $type, bool $checkUnionTypes): Type
    {
        if ($checkUnionTypes || count($type->atomicTypes) < 2) {
            return $type;
        }

        $kept = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Boolean) {
                $kept[] = $atomic;
            }
        }

        return $kept === [] ? $type : Type::fromAtomics(...$kept);
    }

    /** The type with its null member removed, or null where null was all of it. */
    private static function withoutNull(Type $type): ?Type
    {
        $kept = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            $kept[] = $atomic;
        }

        return $kept === [] ? null : Type::fromAtomics(...$kept);
    }

    private static function isThis(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && $atomic->isThis) {
                return true;
            }
        }

        return false;
    }

    private static function isMixed(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
        }

        return false;
    }
}
