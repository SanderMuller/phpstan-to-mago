<?php

declare(strict_types=1);

namespace Examples\Required;

use Symfony\Contracts\Service\Attribute\Required;

final class BadService
{
    #[Required]
    public function setThing(object $thing): void {}
}
