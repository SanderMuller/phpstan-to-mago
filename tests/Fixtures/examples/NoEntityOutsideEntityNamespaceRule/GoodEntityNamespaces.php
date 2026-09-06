<?php

declare(strict_types=1);

namespace Examples\Entity;

use Doctrine\ORM\Mapping\Entity;

/**
 * Three ways to be silent, and the third is a control rather than a case.
 *
 * The namespace carries an `Entity` segment, so the first is silent. The second carries no attribute at all,
 * so the walk answers no. The third is the control on what `getParts()` returns: php-parser includes the
 * declaration's own short name among the parts, so a class *named* `Entity` matches wherever it sits — and
 * dropping the short name would make the port report here while PHPStan stays quiet.
 */
#[Entity]
final class Customer {}

final class Plain {}
