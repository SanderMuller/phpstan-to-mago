<?php

declare(strict_types=1);

namespace Examples\RequireParentConstructCall;

/**
 * Three levels deep on purpose. `Middle` declares no constructor of its own, so the ancestor the message
 * must name is `Base` -- which is the case mago's metadata gets wrong twice over: `parentClasses` is
 * alphabetical rather than nearest-first, and `methodExists()` answers true for a constructor `Middle` only
 * inherits.
 */
class Base
{
    public function __construct() {}
}

class Middle extends Base {}

final class Bad extends Middle
{
    public function __construct()
    {
        $this->prepare();
    }

    private function prepare(): void {}
}
