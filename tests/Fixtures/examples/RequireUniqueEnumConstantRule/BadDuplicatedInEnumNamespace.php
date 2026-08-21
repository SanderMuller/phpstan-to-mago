<?php

declare(strict_types=1);

namespace Examples\Enum;

/**
 * A class-based enum, which is what this rule is about — not a PHP `enum`.
 *
 * `EnumAnalyzer` detects one of three ways: an `@enum` docblock, extending `MyCLabs\Enum\Enum`, or a fully
 * qualified name containing `\Enum\`. This file takes the third.
 */
final class BadDuplicatedInEnumNamespace
{
    public const string RED = 'shared';

    public const string BLUE = 'shared';

    public const string GREEN = 'unique';
}
