<?php

declare(strict_types=1);

namespace Examples\Model;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;

/**
 * Both attributes the rule accepts, so both branches of the folded walk are measured.
 *
 * Neither namespace segment is `Entity`, so each is reported. The second is the one that would go silent if
 * the walk folded to its first name only.
 */
#[Entity]
final class Order {}

#[Embeddable]
final class Address {}
