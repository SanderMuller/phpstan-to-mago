<?php

declare(strict_types=1);

namespace App\Widgets;

/**
 * Two declarers of one method whose flag parameter has a different name in each.
 *
 * The fold's agreement check is the only branch a single-class receiver never reaches, and the emitted
 * plugin has to decline here for the same reason PHPStan does: with two names for the flag, the suggestion
 * the message would make is ambiguous. A receiver typed as the intersection of both yields one class
 * reflection per member, which is what puts more than one iteration through the loop.
 */
interface DimmableOne
{
    public function dim(bool $enabled): void;
}

interface DimmableTwo
{
    public function dim(bool $active): void;
}
