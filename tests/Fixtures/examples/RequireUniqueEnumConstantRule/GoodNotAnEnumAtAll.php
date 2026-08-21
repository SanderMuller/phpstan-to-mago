<?php

declare(strict_types=1);

namespace Examples\Plain;

/** Duplicated values, but nothing marks this as an enum, so the rule declines. */
final class GoodNotAnEnumAtAll
{
    public const string ONE = 'same';

    public const string TWO = 'same';
}
