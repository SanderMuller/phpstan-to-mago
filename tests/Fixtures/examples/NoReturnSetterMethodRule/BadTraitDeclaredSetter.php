<?php

declare(strict_types=1);

namespace Examples\Setters;

/**
 * A setter declared in a trait, which the two engines reach from opposite ends.
 *
 * PHPStan analyses a trait member once per *using* class and answers `getClassReflection()` with that class,
 * so `isClass()` is a question about `TraitUser` here. A mago member hook fires once at the declaration,
 * where the enclosing class-like is the trait — which is neither a class nor an interface nor an enum, so the
 * guard answered no and the port was silent on every trait-declared setter. Measured that way before the
 * branch that asks the users was written.
 *
 * The using class is what makes this a control rather than agreement on zero: without it PHPStan reports
 * nothing either, and the row would pass whether the port looked or not.
 */
trait SetterTrait
{
    private string $name = '';

    public function setName(string $name): string
    {
        $this->name = $name;

        return $name;
    }
}

final class TraitUser
{
    use SetterTrait;
}
