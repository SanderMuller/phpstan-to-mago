<?php

declare(strict_types=1);

namespace Divergence\InstanceofKeepsUnresolvableArm;

use Absent\Vendor\Klass;

/** Resolvable, and the control for the narrowing itself. */
final class Known {}

final class Subject
{
    /**
     * `!instanceof` against a class nothing in the corpus declares.
     *
     * `!$x instanceof T` removes `T` by logic alone — if the value is not an instance of `T`, the `T` arm is
     * gone whether or not `T` can be resolved. Mago eliminates the arm when the class resolves and keeps it
     * as a `ReferenceType` when it does not, so the assertion is conditioned on knowledge it does not need.
     *
     * The shape at `monolog` `Handler/MandrillHandler.php:41`, whose `Swift_Message` is unresolvable because
     * swiftmailer is not installed.
     */
    public function unresolvable(callable|Klass $value): void
    {
        if (! $value instanceof Klass) {
            $value();
        }
    }

    /**
     * The control for the mechanism, not just for the rule: the same narrowing on a class that resolves.
     *
     * Both engines eliminate the arm here, so this case fails if mago's `!instanceof` stops working at all,
     * rather than only when the divergence closes.
     */
    public function resolvable(callable|Known $value): void
    {
        if (! $value instanceof Known) {
            $value();
        }
    }

    /** The control for the rule: a dynamic call both engines report. */
    public function plain(string $other): void
    {
        $other();
    }
}
