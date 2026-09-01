<?php

declare(strict_types=1);

namespace Examples\Inheritance;

/** A concrete class of this project, which the rule asks to be abstract before anything extends it. */
class ConcreteBase
{
    public function handle(): void {}
}

final class BadExtendOfNonAbstract extends ConcreteBase {}

/** A second one, so the pair shows the rule reports per declaration rather than once per file. */
final class AlsoExtendsTheConcreteBase extends ConcreteBase {}
