<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * What this transpiler does with one rule, and why.
 *
 * The verdict and its reason travel together because a count without its reasons is the half nobody can
 * check. Four refusals in one week named a construct that was not what stopped the rule; each read as one
 * table row away, and none would have survived being read next to the rule it describes.
 *
 * `$registered` is a fact about the *package*, not about the rule: a rule a package ships but wires in no
 * neon of its own cannot run for anybody, so it belongs outside every coverage denominator.
 */
final readonly class RuleOutcome
{
    public const string EMIT = 'emit';

    public const string REFUSE = 'refuse';

    /**
     * A rule no plugin could carry, apart from one not translated yet.
     *
     * Both read as "refused" in a list and they are not the same fact. A package holding one of these can
     * never read as full, so a coverage figure that counts it quotes a denominator this tool will never
     * reach.
     */
    public const string NEVER = 'never';

    /**
     * @param self::EMIT|self::REFUSE|self::NEVER $verdict
     * @param list<string>                        $needs
     */
    public function __construct(
        public string $name,
        public string $file,
        public string $verdict,
        public ?string $reason,
        public bool $registered,
        public array $needs,
    ) {}

    public function emitted(): bool
    {
        return $this->verdict === self::EMIT;
    }
}
