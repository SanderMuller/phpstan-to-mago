<?php

declare(strict_types=1);

namespace Divergence\ClassDeclaredTwice;

if (PHP_VERSION_ID >= 80300) {
    class Guarded
    {
        /** The protected member the rule forbids, in the newer branch. */
        protected function forbidden(): void {}
    }
} else {
    class Guarded
    {
        /** The same member, in the older branch, under the same class name. */
        protected function forbidden(): void {}
    }
}

/**
 * The control: one unguarded declaration both engines must report, so silence is a broken run rather than
 * agreement about nothing.
 */
final class Plain
{
    protected function alsoForbidden(): void {}
}
