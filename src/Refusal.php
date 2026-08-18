<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use RuntimeException;

final class Refusal extends RuntimeException
{
    public function __construct(string $reason, ?int $line = null)
    {
        parent::__construct($line === null ? $reason : "$reason (line $line)");
    }
}

/**
 * PHPStan node type -> Mago hook, keyed by fully qualified name.
 *
 * The FQCN matters: `Const_` alone is ambiguous between `Stmt\Const_` (the declaration) and
 * `Node\Const_` (one item of it), and picking the wrong one changes how many findings a rule
 * produces without changing whether it compiles.
 */
