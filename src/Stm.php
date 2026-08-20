<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * One emitted statement, before it is committed to a language.
 *
 * The transpiler used to append Rust source strings directly, which made the emitter and the target
 * language the same thing and meant a second target had to be a second emitter. A statement is now a
 * kind plus its operands, and a Backend renders it. Expression operands still arrive as the
 * `['rust' => .., 'kind' => ..]` descriptors `resolve()` already produced; a second language adds a
 * key to those rather than a second code path here.
 */
final class Stm
{
    /**
     * @param array<string, string> $args the statement's operands, already rendered for the target
     *
     * Rendered rather than structured on purpose: an operand arrives from an expression producer that
     * has already committed to a language, and a Backend's job is the statement around it.
     */
    public function __construct(
        public string $kind,
        public array $args = [],
        public int $indent = 0,
        public bool $unused = false,
    ) {}

}
