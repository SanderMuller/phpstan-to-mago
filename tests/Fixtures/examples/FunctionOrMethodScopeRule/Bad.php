<?php

declare(strict_types=1);

namespace Examples\Scopes;

final class Holder
{
    /** A method is inside a class, so the guard lets it through and the rule reports. */
    public function inside(): void {}
}
