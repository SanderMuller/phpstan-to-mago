<?php

declare(strict_types=1);

namespace Examples\Exception;

use Exception;

final class RefundFailed extends Exception {}

/**
 * An anonymous class extending Exception outside an Exception namespace. The original rule bails through
 * `isAnonymous()`, and the transpiler drops that guard, claiming the class hook never fires for an
 * anonymous class. If that claim is wrong, the port reports here.
 */
final class Thrower
{
    public function throwIt(): Exception
    {
        return new class extends Exception {};
    }
}
