<?php

declare(strict_types=1);

namespace Examples\Dynamic;

function helper(): int
{
    return 1;
}

/**
 * The same five accesses written out, which is what the rule asks for — plus three written *function* names,
 * which this example had none of.
 *
 * That absence is why the rule shipped reporting 169 sites on `nikic/php-parser`. A name written with a leading
 * `\` arrives as an `Identifier` whose child is a `FullyQualifiedIdentifier`, and the written-name predicate
 * descends into that child and did not list the kind — so `\count(..)` read as a dynamic name and every
 * `\`-prefixed global in a library became a finding. The bare call answered correctly all along, which is what
 * made the gap invisible.
 */
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

        // Three written function names: bare, leading-backslash, and namespace-qualified. Only the first was
        // ever answered correctly.
        $bare = count([1]);
        $qualified = \count([1]);
        $namespaced = \Examples\Dynamic\helper();

        return [$constant, $staticProperty, $property, $method, $staticMethod, $name, $bare, $qualified, $namespaced];
    }
}
