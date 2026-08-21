<?php

declare(strict_types=1);

namespace Examples\Marked;

/**
 * The same fault detected the other way: an `@enum` docblock rather than the namespace.
 *
 * @enum
 */
final class BadDuplicatedByAnnotation
{
    public const string ONE = 'same';

    public const string TWO = 'same';
}
