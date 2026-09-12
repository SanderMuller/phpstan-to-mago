<?php

declare(strict_types=1);

namespace Examples\StaticReflection;

/**
 * The three ways the rule fires, which are three different branches of one resolver.
 *
 * `writtenClass()` is the `instanceof` name branch: PHPStan makes a `ConstantStringType` of the spelling and
 * the port makes the literal-string type that reaches the same allow-list test.
 * `lateStaticBinding()` is the branch that looks like an exemption and is not — the rule skips `self` and
 * only `self`, so `static` resolves to a name no allowed prefix covers.
 * `viaIsA()` is the `is_a()` branch, which reads the *second* argument's inferred type rather than a written
 * name, so it exercises the other half of the resolver.
 */
final class BadStaticReflection
{
    public function writtenClass(object $value): bool
    {
        return $value instanceof BadStaticReflection;
    }

    public function lateStaticBinding(object $value): bool
    {
        return $value instanceof static;
    }

    public function viaIsA(object $value): bool
    {
        return is_a($value, BadStaticReflection::class);
    }
}
