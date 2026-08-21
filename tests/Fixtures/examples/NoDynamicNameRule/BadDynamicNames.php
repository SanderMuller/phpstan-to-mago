<?php

declare(strict_types=1);

namespace Examples\Dynamic;

/**
 * Every dynamic spelling the rule reports, across both of its branches and five of its six targets.
 *
 * The rule registers for every expression — `getNodeType()` returns `Expr::class`, which its own comment calls
 * "a trick to allow multiple node types" — and branches on the concrete kind. One branch handles the two
 * static accesses, the other the calls and the property read.
 */
final class BadDynamicNames
{
    public function everyKind(string $name, object $subject, string $class): mixed
    {
        // Branch one: the class part is computed, the member name is written.
        $constant = $class::FIXED;
        $staticProperty = $class::$prop;

        // Branch two: the member name is computed.
        $property = $subject->$name;
        $method = $subject->$name();
        $staticMethod = $class::$name();

        return [$constant, $staticProperty, $property, $method, $staticMethod];
    }
}
