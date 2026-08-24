<?php

declare(strict_types=1);

namespace Examples\Attributes;

use Deprecated;

final class Marked
{
    /**
     * Carries an *imported* attribute. Metadata resolves the name to `Deprecated`, and PHPStan resolves it
     * too — the guard only asks whether there is one, so both stop here.
     */
    #[Deprecated]
    public function marked(): void {}
}
