<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

interface Backend
{
    /** What a bail looks like: Rust needs a value, PHP does not. */
    public function bail(): string;

    public function render(Stm $statement): string;

    /**
     * A call into the support runtime.
     *
     * @param list<string> $args already-rendered operands
     */
    public function call(string $helper, array $args): string;

    /** A string literal as the target's support runtime wants it. */
    public function bytes(string $literal): string;

    /** `if COND then THEN else ELSE`, as an expression. */
    public function conditional(string $condition, string $then, string $otherwise): string;
}
