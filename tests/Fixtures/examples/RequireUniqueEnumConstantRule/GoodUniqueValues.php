<?php

declare(strict_types=1);

namespace Examples\Enum;

/** Detected as a class-based enum, and its values are unique, which is the whole rule. */
final class GoodUniqueValues
{
    public const string RED = 'red';

    public const string BLUE = 'blue';
}
