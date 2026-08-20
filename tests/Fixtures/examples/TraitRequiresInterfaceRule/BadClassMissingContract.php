<?php

declare(strict_types=1);

namespace Examples\Localisation;

use Examples\Contracts\Localised;

/** The same omission on a class, which is the target the hook always had. */
final class BadClassMissingContract
{
    use Localised;
}
