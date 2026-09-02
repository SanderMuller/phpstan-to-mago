<?php

declare(strict_types=1);

namespace Examples\Inheritance;

/** What the rule asks for: the base is abstract. */
abstract class AbstractBase
{
    public function handle(): void {}
}

final class GoodExtendOfAbstract extends AbstractBase {}

/** No parent at all, so there is nothing to judge. */
final class ExtendsNothing
{
    public function handle(): void {}
}

/** A class PHP itself ships, which the rule allows by its own guard rather than by the vendor one. */
final class ExtendsABuiltin extends \Exception {}

/** An interface is not an `extends` for a class, and implementing one leaves the parent null. */
interface Handles
{
    public function handle(): void;
}

final class ImplementsOnly implements Handles
{
    public function handle(): void {}
}
