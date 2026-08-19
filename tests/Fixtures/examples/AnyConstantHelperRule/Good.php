<?php

declare(strict_types=1);

namespace Examples\Constants;

/**
 * No constant is named `ID`, so the helper's loop finds nothing and answers false.
 */
final class Unidentified
{
    public const string NAME = 'name';

    public const string IDENTIFIER = 'identifier';
}
