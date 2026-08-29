<?php

declare(strict_types=1);

namespace Examples\Attributes;

use Attribute;

#[Attribute]
final class NamedMarker
{
    public function __construct(public readonly string $note) {}
}

#[Attribute]
final class GoodRoute
{
    #[NamedMarker(note: 'gone in 2.0')]
    public function handle(): void {}
}
