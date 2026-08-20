<?php

declare(strict_types=1);

namespace App\Reporting;

final class GoodChainedDump
{
    public function ownDump(): void
    {
        // A method of the same name declared by us, not by Laravel. The rule narrows on where the method is
        // *declared*, so this is the guard that needs the codebase rather than the syntax.
        $this->dump();
    }

    public function dump(): void {}
}
