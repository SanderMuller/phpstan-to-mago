<?php

declare(strict_types=1);

namespace Aggregate\Returns;

/** No return type, and nothing above the declaration — the case where both anchors agree. */
final class Untyped
{
    public function open()
    {
        return 1;
    }
}
