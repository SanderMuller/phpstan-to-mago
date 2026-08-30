<?php declare(strict_types=1);

namespace Control;

/**
 * A class that uses a trait and also documents the trait's method in its own docblock.
 *
 * The codebase resolves `m` to the documented declaration, so asking where the name lands says the class
 * does not reach the trait's. PHP disagrees: a `@method` line takes no name away from a trait, and PHPStan
 * analyses the trait's body in this class's context like any other user's.
 *
 * @method m($a)
 */
final class Documenting
{
    use Provided;
}
