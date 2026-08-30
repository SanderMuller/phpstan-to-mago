<?php

declare(strict_types=1);

namespace Examples\Attributes;

use Attribute;

#[Attribute]
final class Grouped
{
    public function __construct(public readonly string $note) {}
}

final class BadGroupedRoute
{
    /**
     * Two attributes in one group, and two groups on one declaration.
     *
     * The rule's node type is the *group*, and it iterates the attributes inside it — so one group holding
     * two positional attributes is two findings from one node, and two groups are two nodes. An example pair
     * using only single-attribute groups cannot tell that reading from one that treats a group as an
     * attribute, and every example here did until this file.
     *
     * The two in one group are on separate lines on purpose. Findings are compared as (file, line, message),
     * so a group written on one line collapses its two findings into one and the pair proves nothing again.
     */
    #[
        Grouped('first'),
        Grouped('second'),
    ]
    #[Grouped('third')]
    public function handle(): void {}
}
