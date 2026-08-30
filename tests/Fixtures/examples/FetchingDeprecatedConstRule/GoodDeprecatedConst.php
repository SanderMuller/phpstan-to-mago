<?php

declare(strict_types=1);

namespace Examples\Deprecations;

final class GoodDeprecatedConst
{
    /** A constant that is not deprecated, read the same way. */
    public function read(): string
    {
        return PHP_EOL;
    }

    /**
     * The same deprecated constant, inside a function that is itself deprecated.
     *
     * The control for the scope check. Every rule in this package opens with it so that deprecated code
     * using deprecated things does not warn, and without the port both engines would disagree here rather
     * than on the constant.
     *
     * @deprecated
     */
    public function readInsideDeprecated(): string
    {
        return FILTER_SANITIZE_STRING;
    }
}
