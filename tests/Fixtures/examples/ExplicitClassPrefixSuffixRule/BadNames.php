<?php

declare(strict_types=1);

namespace Examples\Naming;

/**
 * One violation per class-like kind, because the rule registers for all four and answers each from the
 * node's own kind. An interface that does not end with "Interface" is the first.
 */
interface BadPaymentGateway
{
    public function charge(): void;
}

/**
 * An interface whose name ends with "Trait", which reports the trait message and nothing else.
 *
 * This is the control for the fall-through: the helper reports that under a guard and *returns*, then falls
 * through to the interface message. Emitted without the return it would report both, and PHPStan reports one.
 */
interface BadPaymentTrait
{
    public function charge(): void;
}

/** A trait that does not end with "Trait". */
trait BadPaymentHelpers
{
    public function help(): void {}
}

/** An abstract class that does not start with "Abstract". */
abstract class BadPaymentBase
{
    abstract public function run(): void;
}
