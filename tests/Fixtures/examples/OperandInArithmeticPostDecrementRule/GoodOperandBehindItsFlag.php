<?php

declare(strict_types=1);

namespace Examples\PostDecrement;

/**
 * Operands that report only once a flag is set, and this gate runs at the level-0 defaults.
 *
 * A bare `object` and a union both follow `checkUnionTypes`, and `?int` needs `checkNullables` with it —
 * without that flag PHPStan strips the null and checks the `int` that is left. All three are constructor
 * parameters on the emitted plugin, so a consumer at level 7 gets what the original gives there.
 */
final class OperandBehindItsFlag
{
    public function behindAFlag(object $bare, int|bool $intOrBool, int|string $intOrString, ?int $nullableInt): void
    {
        $bare--;
        $intOrBool--;
        $intOrString--;
        $nullableInt--;
    }
}
