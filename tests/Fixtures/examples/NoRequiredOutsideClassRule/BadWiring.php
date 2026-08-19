<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Contracts\Service\Attribute\Required;

trait BadWiring
{
    #[Required]
    public function setByAttribute(object $thing): void {}

    /**
     * @required
     */
    public function setByAnnotation(object $thing): void {}
}
