<?php

declare(strict_types=1);

namespace Examples\Setters;

use Doctrine\ORM\Mapping\Entity;

/**
 * The rule's first guard, which nothing else in this pair exercises.
 *
 * `$node->attrGroups !== []` skips any method carrying an attribute at all — the rule's own comment calls it
 * "possibly some important logic". Both setters below return a value and both engines stay silent, so a port
 * that stopped answering the attribute question would report here.
 *
 * `AttributedInOrphan` varies one axis: its parent cannot be resolved. The port answers the question from
 * metadata — `enclosingClassName()` then `getMethod()` — while the rule reads the syntax tree, and mago is
 * known to skip the bodies of classes whose parent it cannot find. So this is the row where a metadata read
 * could answer "no attributes" for a method that carries one, which would let the guard through and report
 * where PHPStan skips. Measured: it does not. That is what licenses the `->attrGroups` mapping's claim that
 * an empty group list means no attributes.
 */
final class AttributedSetters
{
    #[Entity]
    public function setName(string $name): string
    {
        return $name;
    }
}

class AttributedInOrphan extends NoSuchParentAnywhere
{
    #[Entity]
    public function setName(string $name): string
    {
        return $name;
    }
}
