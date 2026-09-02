<?php

declare(strict_types=1);

namespace Examples\Rector\CodeQuality;

/** Named `*Rector` but in no version namespace, so it needs no version contract. */
final class GoodVersionlessRector
{
    public function refactor(): void {}
}
