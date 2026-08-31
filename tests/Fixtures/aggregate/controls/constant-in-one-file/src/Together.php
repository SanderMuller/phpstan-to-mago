<?php declare(strict_types=1);

namespace Control;

/**
 * A trait and the only class that uses it, in one file.
 *
 * The codebase lists the trait's constant on the class as well, with the trait's own declaring location — and
 * that location is in this file, so a file-equality test reads it as written by the class and counts it a
 * second time. Containment in the class-like's own span is what separates them.
 */
trait Together
{
    const SHARED = 1;
}

final class Apart
{
    use Together;
}
