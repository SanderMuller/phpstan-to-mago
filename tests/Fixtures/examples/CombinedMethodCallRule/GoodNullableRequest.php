<?php

declare(strict_types=1);

namespace App\Intake;

use Illuminate\Http\Request;

/**
 * A request receiver that *might* be null, which the original does not treat as a request.
 *
 * `getObjectClassNames()` answers about a type that is certainly an object, and `Request|null` is not one, so
 * the original declines here. A port that collected the object members and ignored the rest reported it —
 * found on a real project, on two Nova actions holding `protected ?ActionRequest $request = null`.
 *
 * Kept as an example rather than fixed only in the helper, because "ignore null" is right for other checks:
 * the positional-flag one strips it deliberately, and that was measured too. The difference is what the
 * original asks for, and only a pair can hold both answers at once.
 */
final class GoodNullableRequest
{
    private ?Request $request = null;

    public function readThroughNullableRequest(): mixed
    {
        return $this->request->input('anything');
    }
}
