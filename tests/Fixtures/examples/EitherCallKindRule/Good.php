<?php

declare(strict_types=1);

namespace Examples\Calls;

function forbidden(): void {}

final class Neither
{
    public function allowed(): void {}

    /**
     * A plain function call, which the guard declines even though the plugin registers that kind, and a
     * method whose name the rule does not name.
     */
    public function call(): void
    {
        forbidden();
        $this->allowed();
    }
}
