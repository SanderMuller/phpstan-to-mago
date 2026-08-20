<?php

declare(strict_types=1);

namespace Examples\Localisation;

use Examples\Contracts\Localised;
use Examples\Contracts\LocalisedContract;

/** Uses the trait and implements the contract, which is the whole point of the rule. */
final class GoodImplementsContract implements LocalisedContract
{
    use Localised;

    public function locale(): string
    {
        return 'nl';
    }
}
