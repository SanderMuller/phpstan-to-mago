<?php

declare(strict_types=1);

namespace Examples\ArithmeticMinus;

/**
 * Two unions that report only once a flag is set, and this gate runs at the level-0 defaults.
 *
 * `checkUnionTypes` is what makes `int|bool` report, and `?int` needs `checkNullables` as well: without
 * it PHPStan strips the null and checks the `int` that is left. Both flags are constructor parameters on the
 * emitted plugin, so a consumer running level 7 gets the reports the original gives there.
 */
final class UnionBehindItsFlag
{
    public function unions(int|bool $intOrBool, ?int $nullableInt): void
    {
        echo -$intOrBool;
        echo -$nullableInt;
    }
}
