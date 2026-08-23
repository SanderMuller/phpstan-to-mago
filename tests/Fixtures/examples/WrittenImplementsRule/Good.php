<?php

declare(strict_types=1);

namespace Examples\Implementing;

interface Allowed {}

/** Writes an `implements` clause, so the guard exits. */
final class Direct implements Allowed {}

/** Not a class, so the kind guard exits before the interface list is read. */
enum Suit
{
    case Hearts;
}
