<?php declare(strict_types=1);

namespace Control;

/**
 * Declares the constant the trait declares. The trait's own declaration is still analysed in this class's
 * context, so both are counted — which is where this collector parts company with the return one.
 */
final class Overrider
{
    use Shared;

    const FROM_TRAIT = 2;
}
