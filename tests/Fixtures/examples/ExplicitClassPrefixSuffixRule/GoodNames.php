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
 * An anonymous class, which is the case the `$node->name instanceof Identifier` guard filters out.
 *
 * Mago gives it `NodeKind::AnonymousClass`, which this plugin does not register, so the hook never fires
 * here. Kept in the good example so the silence is checked rather than argued.
 *
 * **The silence is over-determined, and the last clause of this comment used to claim otherwise.** It said
 * the port "would report the missing Abstract prefix if the hook ever fired". It would not: an anonymous
 * class cannot be abstract — `new abstract class` is a syntax error — so the prefix branch does not apply,
 * and its name ends with no suffix the rule looks for. Measured by registering the kind and neutering the
 * guard: the rule proceeds and still reports nothing. So this example pins the outcome, not the mechanism,
 * and a change that made the hook fire here could not be told from one that did not.
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
