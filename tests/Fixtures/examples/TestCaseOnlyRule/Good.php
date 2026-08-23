<?php

declare(strict_types=1);

namespace Examples\Cases;

/**
 * Reaches nothing the gate names, so its method is passed over. The example that matters: the gate's whole
 * job is the ancestry test, and without a class failing it the rule would pass for reporting everything.
 */
final class Unrelated
{
    public function declared(): void {}
}
