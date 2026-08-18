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
    /** @param array<string, mixed> $args */
    public function __construct(
        public string $kind,
        public array $args = [],
        public int $indent = 0,
        public bool $unused = false,
    ) {}

}

/** Renders {@see Stm} nodes into a target language. */
