<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * The whole point of the cross-file check: `rules()` is declared in another file.
 *
 * A node hook is handed one file, so this is the example the port could not have answered before the check
 * moved to a whole-project pass. `street` is validated through the `address.street` root its parent
 * declares; `nickname` is not.
 */
final class BadInheritedRules extends BadCombinedMethodCalls
{
    public function readInherited(): void
    {
        $this->input('address');
        $this->input('nickname');
    }
}
