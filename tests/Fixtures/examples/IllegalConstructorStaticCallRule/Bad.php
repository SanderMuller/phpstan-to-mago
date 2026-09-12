<?php

declare(strict_types=1);

namespace Examples\IllegalConstructorStaticCall;

class StaticCallBase
{
    public function __construct() {}
}

final class Unrelated
{
    public function __construct() {}
}

final class BadForeignStaticCall
{
    /** `__construct()` reached statically on a class that is not a parent, which is what the rule forbids. */
    public function __construct()
    {
        Unrelated::__construct();
    }
}

final class BadOutsideConstructor extends StaticCallBase
{
    /**
     * The parent constructor, reached from a method that is not the constructor.
     *
     * This is the control for the guard the port drops. PHPStan reaches the trait-alias branch only when
     * the enclosing function's name is not `__construct`; here it is `reset`, no alias is involved, and the
     * rule reports. A port that answered the enclosing function's name wrongly would be silent on this line
     * and still pass the two cases above it.
     */
    public function reset(): void
    {
        parent::__construct();
    }
}

trait PlainTraitInitialiser
{
    /**
     * The parent constructor, reached from an ordinary trait method.
     *
     * The row that agrees because the dropped branch never mattered. PHPStan analyses a trait body once per
     * using class and the enclosing function's name here is `initialise`, so it reaches
     * `isInRenamedTraitConstructor()`, finds no alias for that name, and reports. The port has no branch to
     * reach and reports for the same reason the guard above it fails.
     */
    public function initialise(): void
    {
        parent::__construct();
    }
}

final class UsesPlainTraitInitialiser extends StaticCallBase
{
    use PlainTraitInitialiser;
}
