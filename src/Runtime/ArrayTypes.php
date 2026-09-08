<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;

/**
 * Whether an inferred type is an array, which PHPStan spells as a count of `getArrays()`.
 *
 * Its own class rather than a method on {@see Types} or {@see RuleLevel}, and that is a measurement rather
 * than a preference: added to `Types` it took that class to 82 against a limit of 80, and moved to
 * `RuleLevel` — beside `everyAtomicIsString()`, which is the same shape — it took *that* one to 81. Both are
 * at their ceiling, so a reader with no room in either belongs next to them instead of inside one.
 *
 * Splitting the nine pure atomic-shape readers out of `RuleLevel` was tried first and is the better long-term
 * shape; it is a bigger change than this rule needs and is recorded in `VERIFICATION.md` as the next split
 * rather than done under a feature.
 */
final class ArrayTypes
{
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
     * ## This is inert in `OperandsInArithmeticAdditionRule`, and the port keeps it anyway
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
}
