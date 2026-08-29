<?php

declare(strict_types=1);

namespace Examples\Naming;

/** The same three kinds, each named the way the rule asks. */
interface GoodPaymentGatewayInterface
{
    public function charge(): void;
}

trait GoodPaymentHelpersTrait
{
    public function help(): void {}
}

abstract class AbstractGoodPaymentBase
{
    abstract public function run(): void;
}

/**
 * An anonymous class, which is the case the dropped `$node->name instanceof Identifier` guard filtered out.
 *
 * Mago gives it `NodeKind::AnonymousClass`, which this plugin does not register, so the guard cannot hold and
 * dropping it is sound. Kept in the good example so that claim is checked rather than argued: the port has to
 * stay silent here, and it would report the missing "Abstract" prefix if the hook ever fired.
 */
final class GoodPaymentFactory
{
    public function make(): object
    {
        return new class {
            public function run(): void {}
        };
    }
}
