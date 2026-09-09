<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;

/**
 * Whether an inferred type is one *written value* rather than a set of possible ones.
 *
 * Split from {@see Types} rather than added to it: the literal-true question took that class to 82 against a
 * limit of 80, and the runtime being out of the baseline is a property this repository keeps rather than a
 * figure it tracks. Grouped by the question, so the string one moved across too -- both ask "is every part of
 * this type one literal", and both answer PHPStan's `->yes()` rather than its `->maybe()`.
 *
 * {@see Support} still delegates to both, so no emitted byte moved: a plugin calls `Support::x()` and the
 * facade decides where that lives. The delegations cost nothing, which is the measurement the first split of
 * `Support` was made on.
 */
final class LiteralTypes
{
    /**
     * Whether the inferred type is exactly the literal `true`.
     *
     * PHPStan's rules spell this `(new ConstantBooleanType(true))->isSuperTypeOf($type)->yes()`, which holds
     * only where every part of the type is that one literal -- `true` yes, `bool` no, `true|false` no. So the
     * test is per atomic, the same way {@see typeIsBoolean()} is, with the refinement read as well as the
     * kind.
     *
     * **The refinement is the part a boolean-only check would miss**, and this repository has the record of
     * getting exactly that wrong once: a predicate reached a refinement, read one field off it and not the
     * one four lines below, and produced a table saying mago does not narrow where it does. A `bool` and a
     * `true` are the same `ScalarTypeKind` and differ only here.
     */
    public static function typeIsLiteralTrue(?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof ScalarType
                || $atomic->kind !== ScalarTypeKind::Boolean
                || $atomic->refinement !== true
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every part of a type is a literal string — PHPStan's `Type::isLiteralString()->yes()`.
     *
     * Every atomic has to be one, which is what `yes` means: `'a'|int` is a `maybe` there and is not one here
     * either, and an empty type is not a literal string. Answered from the same refinement
     * {@see constantStringsOf()} reads, so the two cannot drift — including the `ClassLikeString` a `::class`
     * expression produces, which PHPStan also calls a constant string.
     */
    public static function typeIsLiteralString(?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        return count(Types::constantStringsOf($type)) === count($type->atomicTypes);
    }
}
