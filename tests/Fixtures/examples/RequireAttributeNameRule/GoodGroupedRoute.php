<?php

declare(strict_types=1);

namespace Examples\Attributes;

final class GoodGroupedRoute
{
    /** Same two shapes, every argument named, so neither tool reports. */
    #[
        Grouped(note: 'first'),
        Grouped(note: 'second'),
    ]
    #[Grouped(note: 'third')]
    public function handle(): void {}
}
