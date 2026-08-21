<?php

declare(strict_types=1);

namespace Examples\Dynamic;

/** The same five accesses written out, which is what the rule asks for. */
final class GoodWrittenNames
{
    public function everyKind(Holder $subject): mixed
    {
        $constant = Holder::FIXED;
        $staticProperty = Holder::$prop;
        $property = $subject->inst;
        $method = $subject->run();
        $staticMethod = Holder::make();

        // `::class` is written out too, and the rule skips it by name rather than by shape.
        $name = Holder::class;

        return [$constant, $staticProperty, $property, $method, $staticMethod, $name];
    }
}
