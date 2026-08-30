<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use RuntimeException;

final class Refusal extends RuntimeException
{
    /**
     * Whether no vocabulary, hook or body change could ever move this rule.
     *
     * Two refusals differ in kind and read the same in a list. "Not translated yet" is work; "reports nothing
     * a plugin could carry" is a property of the rule, and a package holding one can never be fully covered.
     * Counting them together makes every per-package figure quote a denominator that includes rules no
     * version of this tool will reach — which matters most exactly when a package approaches full and the
     * number starts being quoted.
     *
     * Set only where the transpiler already knows: {@see Transpiler::refuseWhatNoBodyCouldFix()}, whose whole
     * job is the shapes no body could fix. Anything else is provisional by default, which is the safe
     * direction — a refusal wrongly called permanent stops someone looking.
     */
    public function __construct(
        string $reason,
        ?int $line = null,
        public readonly bool $permanent = false,
    ) {
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
