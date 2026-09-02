<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
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
 * Ported for the boolean-condition family. Not for the arithmetic one: this holds `passesAsBoolean` and
 * nothing else. `OperatorRuleHelper::isValidForArithmeticOperation` is here too now, and its own docblock
 * carries the table it was measured from; the six binary rules still refuse on a shape as well, an
 * `if`/`elseif` chain binding two operands per branch, so the helper alone does not reach them.
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
 *
 * ## Three divergences, all stated rather than discovered
 *
 * - **Mixed passes.** `passesAsBoolean` opens with `if ($type instanceof MixedType) return
 *   !$type->isExplicitMixed();`, outside `findTypeToCheck` and live at every level. Mago's `MixedType`
 *   carries `issetFromLoop`, `nonNull`, `empty` and `truthiness` and no explicit flag, so the distinction
 *   cannot be made. Passing matches implicit mixed and under-reports explicit, which surfaces as
 *   `only-original` -- the direction to choose when one must be chosen.
 * - **Benevolent unions are invisible, and this is the one that bites.** Mago has no counterpart:
 *   "benevolent" appears nowhere in the SDK and `TypeFlags` carries eleven flags, none of them it. PHPStan
 *   filters a benevolent union's failing members when `checkBenevolentUnionTypes` is false, keeps the
 *   `false` member -- which *is* a boolean -- and stays silent. A port that cannot see benevolence is
 *   strict there, which is the unsafe direction.
 *
 *   This was accepted on a measurement of **zero** benevolent operands in condition position, and that
 *   measurement was of the wrong position. The `!` operand is where this family does most of its work, and
 *   `UploadedFile::getRealPath()` is benevolent `(string|false)` there. Five sites on Shopware, controlled
 *   side by side in one file: `(string|false)` reports nothing and a plain `string|false` from an ordinary
 *   `@return` reports, same expression shape, same run.
 *
 *   So it is a real divergence with a count rather than a hypothetical one. It cannot be closed from this
 *   side -- there is nothing on a mago `Type` to read benevolence from -- and the only honest response is
 *   to say where it happens and how often.
 * - **A call in condition position has no inferred type at all,** so `passesAsBoolean` is handed null and
 *   passes. Measured over one consumer's `app/`: **442** of the conditions these three rules read carry no
 *   type, 441 of them a call and one an `instanceof` against a class mago cannot resolve. Every
 *   `FileAnalysisRequirement` was declared at once -- `ExpressionTypes`, `TargetExpressionTypes`,
 *   `ReceiverType`, `ArgumentTypes` -- and the count did not move, so it is not a requirement the plugin
 *   forgot to ask for. Reading the callee's *declared* return type instead was attempted in a probe and does
 *   not price out: it answers `none` for `$response->successful()`, whose signature says `bool`, because the
 *   receiver of a chained call is the same absence one level down.
 *
 *   Silence, so the safe direction, and the population is far larger than the disagreement it contributes
 *   to: 442 untyped and 1292 `mixed` conditions in that directory against 151 `only-original` findings over
 *   the whole 2932-file corpus. Most of both populations is something PHPStan passes as well.
 */
final class RuleLevel
{
    /** The scalar kinds the accepted type `int|float|numeric-string` covers, once mago has dropped the accessory. */
    private const array NUMERIC = [ScalarTypeKind::Integer, ScalarTypeKind::Float];

    /** Those, plus the one other scalar kind that coerces to a number: `bool`. `null` is not a scalar here. */
    private const array NUMERIC_OR_COERCIBLE = [ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::Boolean];

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

        // `ErrorType` is a *pass*, not a failure: `passesAsBoolean` returns true on it, so every branch that
        // answers it has to silence the rule rather than report. Returning false here instead was an
        // inversion that no test caught until a bare `object` reached one of those branches, because the
        // others were shadowed -- mixed by the check above, and an all-null subject by being rare.
        $found = self::findTypeToCheck($type, $checkNullables, $checkUnionTypes);
        if (! $found instanceof Type) {
            return true;
        }

        return Types::typeIsBoolean($found);
    }

    /**
     * Whether an expression's type is a valid arithmetic operand, the way `OperatorRuleHelper` decides it.
     *
     * The sibling of {@see passesAsBoolean()}, and a shorter port than the original reads, because two of
     * PHPStan's four branches never engage. The whole answer was measured on a real PHPStan run over one
     * operand of every shape. `internal/probe-arithmetic-atomics.php` runs both halves of that measurement —
     * the atomics mago gives at the position the rule reads, and the real rule over the same file at each
     * flag setting — and prints this:
     *
     * | operand                    | reports when                           |
     * |:---------------------------|:---------------------------------------|
     * | `bool`, `true`, `null`     | always                                 |
     * | `int\|bool`                | `checkUnionTypes`                      |
     * | `?int`                     | `checkNullables` and `checkUnionTypes` |
     * | everything else measured   | never                                  |
     *
     * "Everything else" is `int`, `float`, `string`, `numeric-string`, `array`, a named object, a bare
     * `object`, `mixed`, `int|string` and `int|float`. A literal `true` reports like any other boolean,
     * because its atomic is a boolean scalar carrying a refinement rather than a kind of its own. `checkThisOnly` silences all of it, the same way it
     * silences the boolean family, which is why the gate sets it false for both.
     *
     * ## Why two of the original's branches are not here
     *
     * - **`$type->toNumber() instanceof ErrorType` returns *true*, a pass** — the comment on it says "already
     *   reported by PHPStan core". So every type that cannot coerce at all is silent, which is what makes
     *   `string`, `array` and every object shape silent above. Here that is one test rather than a port of
     *   `toNumber()`: a type is a candidate only where every atomic is `int`, `float`, `bool` or `null`.
     * - **The operator-overloading branch is unreachable.** It asks whether an *object* type accepts `+ 1`,
     *   and an object never gets past the branch above. Measured rather than reasoned: a named object and a
     *   bare `object` are both silent on the real run, at every flag setting in the table.
     *
     * ## And why `numeric-string` needs no accessory type
     *
     * PHPStan accepts `string&numeric-string` and rejects a plain `string` — but it rejects it through
     * `toNumber()`, so both are silent. Mago drops the accessory anyway: measured, a `numeric-string`
     * parameter and the literal `'12'` both arrive as a bare `ScalarType(string)`. Treating every string as
     * a non-candidate agrees with the original on both, and there is no third string to disagree about.
     */
    public static function isValidForArithmeticOperation(
        ?Type $type,
        bool $checkNullables,
        bool $checkUnionTypes,
        bool $checkThisOnly,
    ): bool {
        if (! $type instanceof Type || self::isMixed($type)) {
            return true;
        }

        if (! self::everyAtomicCoercesToNumber($type)) {
            return true;
        }

        // `findTypeToCheck`'s own short-circuit, which silences every subject that is not `$this` at levels 0
        // and 1. Below the branch above rather than at the top, because the order is the original's: the
        // coercion test runs before `isSubtypeOfNumber()` is reached, and only that call reads the flag.
        if ($checkThisOnly && ! self::isThis($type)) {
            return true;
        }

        return self::passesAsNumber($type, $checkNullables, $checkUnionTypes);
    }

    /**
     * Whether an expression's type is a valid increment or decrement operand.
     *
     * One function for both, and for `++` and `--` alike, because the port cannot separate them — see the
     * divergence below. Measured the same way as its arithmetic sibling, by
     * `internal/probe-increment-operands.php`, which runs all four rules over one operand of every shape at
     * each flag setting and prints what reports:
     *
     * | operand                                   | reports when                           |
     * |:------------------------------------------|:---------------------------------------|
     * | `bool`, `null`, `array`, a named object   | always                                 |
     * | a bare `object`, `int\|bool`, `int\|string` | `checkUnionTypes`                      |
     * | `?int`                                    | `checkNullables` and `checkUnionTypes` |
     * | `int`, `float`, `numeric-string`, `mixed` | never                                  |
     * | a plain `string`                          | `--` and `$x--` only, never `++`       |
     *
     * Note how much wider this is than the arithmetic family: an `array` and an object report here at every
     * setting, and they never report there. The original is why — `isValidForIncrement()` and
     * `isValidForDecrement()` have no `toNumber()` pass, so nothing hands those shapes to PHPStan core.
     * Reusing the arithmetic table would have silenced the largest part of this rule's population.
     *
     * ## The one divergence, and which direction it was chosen in
     *
     * `isValidForIncrement()` passes a string outright — its comment says `$a = 'a'; $a++;` is valid PHP —
     * and `isValidForDecrement()` does not. So PHPStan reports `--$text` on a plain string and says nothing
     * about `--$numeric` on a `numeric-string`, which `isSubtypeOfNumber()` accepts.
     *
     * Mago erases that distinction: measured in `internal/probe-arithmetic-atomics.php`, a `numeric-string`
     * parameter arrives as a bare `ScalarType(string)`, the same atomic a plain `string` gives. So the port
     * has to answer both the same way, and it passes them: a decrement of a plain string goes unreported,
     * where the other choice would report every decrement of a numeric string. Under-reporting is the
     * direction this repository picks when one has to be picked, because it surfaces as `only-original` in a
     * differential rather than as a finding nobody can act on.
     *
     * The increment half is exact — PHPStan passes every string there too.
     */
    public static function isValidForIncrementOrDecrement(
        ?Type $type,
        bool $checkNullables,
        bool $checkUnionTypes,
        bool $checkThisOnly,
    ): bool {
        if (! $type instanceof Type || self::isMixed($type)) {
            return true;
        }

        if (self::everyAtomicIsString($type)) {
            return true;
        }

        if ($checkThisOnly && ! self::isThis($type)) {
            return true;
        }

        return self::passesAsNumber($type, $checkNullables, $checkUnionTypes);
    }

    /**
     * `findTypeToCheck` with the accepted type `int|float|numeric-string`, and the test that follows it.
     *
     * The narrowing is the same function the boolean family reaches, and the criteria is the difference: it
     * keeps the union members that *satisfy* the accepted type, and where none does it keeps the whole union
     * so the check sees what the rule was handed. That is what makes `int|bool` silent without
     * `checkUnionTypes` — the `int` satisfies, so that is all the check looks at — and reporting with it.
     */
    private static function passesAsNumber(Type $type, bool $checkNullables, bool $checkUnionTypes): bool
    {
        if (! $checkNullables && ! self::isNullOnly($type)) {
            $type = self::withoutNull($type);
        }

        // A bare `object` follows the same flag it follows for the boolean family, where PHPStan answers
        // `ErrorType` and both callers read that as a pass. Measured here too: a bare `object` is silent
        // without the flag and reports with it, while a *named* object reports either way.
        if (! $checkUnionTypes && self::isBareObject($type)) {
            return true;
        }

        return self::everyAtomicIsNumber(self::keepTheNumbersOf($type, $checkUnionTypes));
    }

    /**
     * The members of a union that are numbers, or the whole type where none of them is.
     *
     * `filterUnion()` is the same shape for the boolean criteria. Kept apart rather than parameterised with
     * a callable: these run inside every emitted plugin, and the two criteria are two tables of atomic
     * kinds rather than two behaviours.
     */
    private static function keepTheNumbersOf(Type $type, bool $checkUnionTypes): Type
    {
        if ($checkUnionTypes || count($type->atomicTypes) < 2) {
            return $type;
        }

        $kept = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof ScalarType && in_array($atomic->kind, self::NUMERIC, true)) {
                $kept[] = $atomic;
            }
        }

        return $kept === [] ? $type : Type::fromAtomics(...$kept);
    }

    /** Whether every part of a type is a string, which is `Type::isString()->yes()`. */
    private static function everyAtomicIsString(Type $type): bool
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
    private static function everyAtomicCoercesToNumber(Type $type): bool
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

    /** Whether every part of a type is `int` or `float`, which is what the accepted type covers. */
    private static function everyAtomicIsNumber(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof ScalarType || ! in_array($atomic->kind, self::NUMERIC, true)) {
                return false;
            }
        }

        return $type->atomicTypes !== [];
    }

    /**
     * The type `findTypeToCheck` narrows to, or null where it answers `ErrorType`.
     *
     * `ErrorType` is not an error to the callers: both read it as *pass*, so null here means "say nothing".
     */
    private static function findTypeToCheck(Type $type, bool $checkNullables, bool $checkUnionTypes): ?Type
    {
        // `!$type->isNull()->yes()` guards PHPStan's own removal, so a subject that *is* null keeps its type
        // and reports. Stripping unconditionally silenced it instead -- an under-report measured against a
        // real run, where PHPStan reports `null given` at level 7.
        if (! $checkNullables && ! self::isNullOnly($type)) {
            $type = self::withoutNull($type);
        }

        if (self::isMixed($type)) {
            return null;
        }

        // A bare `object` with no class name behind it. PHPStan answers `ErrorType` here, so it stays silent
        // below level 7 and reports from 7 up -- measured on a real run at every level from 0 to 10, because
        // this branch had been written down as dead-by-configuration and it is not. Without it the port
        // reports where PHPStan is quiet, which is the direction that ships a finding nobody can act on, and
        // `phpstan-strict-rules` registers through its own config so a consumer can run these families at
        // level 5.
        if (! $checkUnionTypes && self::isBareObject($type)) {
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

    /** Whether null is the whole type, which is what stops PHPStan removing it. */
    private static function isNullOnly(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof SimpleAtomicType || $atomic->kind !== SimpleAtomicTypeKind::Null) {
                return false;
            }
        }

        return true;
    }

    /** The type with its null member removed. Never called where null is all of it. */
    private static function withoutNull(Type $type): Type
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

    /** Every member an object, and none of them named -- PHPStan's `isObject()->yes()` with no class names. */
    private static function isBareObject(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof AnyObjectType) {
                return false;
            }
        }

        return true;
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
