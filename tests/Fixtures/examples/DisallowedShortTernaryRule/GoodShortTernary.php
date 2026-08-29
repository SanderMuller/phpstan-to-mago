<?php

declare(strict_types=1);

namespace Examples\Conditions;

final class GoodShortTernary
{
    /**
     * A full ternary, which this rule allows.
     *
     * The pair reads the same `Conditional` arm count as `BooleanInTernaryOperatorRule` and asks the opposite
     * question, so between them the two spellings are checked in both directions: this file must be silent
     * where that one's good file reports nothing, and vice versa.
     */
    public function label(string $name): string
    {
        return $name !== '' ? $name : 'anonymous';
    }

    /** A null coalesce, which is a different node kind and reaches this hook not at all. */
    public function fallback(?string $name): string
    {
        return $name ?? 'anonymous';
    }
}
