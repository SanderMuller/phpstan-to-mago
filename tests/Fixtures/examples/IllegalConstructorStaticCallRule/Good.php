<?php

declare(strict_types=1);

namespace Examples\IllegalConstructorStaticCall;

final class GoodParentStaticCall extends StaticCallBase
{
    /** The parent constructor, reached the one way the rule allows. */
    public function __construct()
    {
        parent::__construct();
    }

    /** An ordinary static call, which the name guard has to let through. */
    public function other(): void
    {
        Unrelated::class;
    }
}

trait AliasedConstructor
{
    /**
     * The parent constructor, from a trait constructor a using class renames.
     *
     * The row the dropped branch was written for, and the axis this pair varies against the plain trait
     * method in `Bad.php`. PHPStan sees the *alias* as the enclosing function's name -- measured:
     * `function=initialise trait=AliasedConstructor class=UsesAliasedConstructor` -- so its
     * `__construct` guard fails and only `isInRenamedTraitConstructor()` keeps it quiet. Mago fires once at
     * the declaration and reads the declared name, so the guard passes and the parent check keeps it quiet
     * instead. Two routes, one outcome, and this file is what says so.
     */
    public function __construct()
    {
        parent::__construct();
    }
}

final class UsesAliasedConstructor extends StaticCallBase
{
    use AliasedConstructor { __construct as initialise; }

    public function __construct()
    {
        $this->initialise();
    }
}
