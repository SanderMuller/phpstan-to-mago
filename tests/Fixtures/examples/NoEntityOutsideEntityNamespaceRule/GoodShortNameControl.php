<?php

declare(strict_types=1);

namespace Examples\Model;

use Doctrine\ORM\Mapping\Entity as EntityAttribute;

/**
 * The control the good file's docblock names, kept in its own namespace so it varies one axis.
 *
 * No segment of `Examples\Model` is `Entity`, but the declaration's own short name is — and `getParts()`
 * includes it. Both engines stay silent, so a port that dropped the short name would report here alone.
 */
#[EntityAttribute]
final class Entity {}
