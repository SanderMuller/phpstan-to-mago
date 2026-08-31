<?php declare(strict_types=1);

namespace Control;

/**
 * An enum's cases are `EnumCase` nodes and never `ClassConst` ones, so the collector counts none of them. The
 * constant beside them is counted like any other.
 */
enum Colour: string
{
    case Red = 'red';
    case Blue = 'blue';

    const DEFAULT = self::Red;
}
