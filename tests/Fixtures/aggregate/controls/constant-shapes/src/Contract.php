<?php declare(strict_types=1);

namespace Control;

/**
 * An interface constant. It is counted here, on its own declaration, and it does *not* guard the class that
 * implements the interface — `getParents()` is parent classes only.
 */
interface Contract
{
    const ON_INTERFACE = 'i';
}
