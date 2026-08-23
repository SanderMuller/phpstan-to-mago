<?php

declare(strict_types=1);

namespace Examples\LooseNames;

final class Caller
{
    public function forbidden(): void {}

    public function alsoForbidden(): void {}

    /**
     * Both names the haystack holds, reached as method calls so the hook fires.
     */
    public function call(self $other): void
    {
        $other->forbidden();
        $other->alsoForbidden();
    }
}
