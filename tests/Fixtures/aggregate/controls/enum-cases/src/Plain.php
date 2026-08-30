<?php declare(strict_types=1);

namespace Control;

/**
 * And a pure enum, which gets `cases()` and not the other two.
 */
enum Plain
{
    case One;

    public function label(): string
    {
        return $this->name;
    }
}
