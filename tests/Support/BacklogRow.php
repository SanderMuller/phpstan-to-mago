<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * One refused rule, typed so {@see Backlog} reads as a comparison rather than as offset arithmetic.
 */
final class BacklogRow
{
    /** @param list<string> $needs */
    public function __construct(
        public readonly string $name,
        public array $needs = [],
        public int $stepped = 0,
        public string $covered = '',
        public ?int $findings = null,
    ) {}

    /** Whether every obstacle names a value nobody can supply, which is a stop rather than a cost. */
    public function isStop(): bool
    {
        foreach ($this->needs as $need) {
            if (! str_contains($need, 'constructor parameter')
                && ! str_contains($need, 'computed in the constructor')
                && ! str_contains($need, 'container parameter')
                && ! str_contains($need, 'wired by')
            ) {
                return false;
            }
        }

        return $this->needs !== [];
    }

    /**
     * @return array{bool, bool, int, int, int, string} the ordering key, in the order the axes decide
     *
     * **Yield sits above every structural axis, and below coverage and the stop.** A rule that fires nothing
     * on real code is worth nothing however cheap, which this script's closing line has always said and its
     * ordering could not act on. Measured yield is optional -- {@see Backlog::rows()} takes it from a
     * `run-refusal-yield.php` report -- so an unmeasured run ranks exactly as before rather than pretending
     * to a number it does not have.
     *
     * Below coverage and the stop because those are facts about the rule and yield is a fact about a corpus:
     * a check a sibling already ships is worthless at any yield, and a value nobody can supply is unbuildable
     * at any yield.
     */
    public function rank(): array
    {
        return [
            $this->covered !== '',
            $this->isStop(),
            -($this->findings ?? 0),
            $this->stepped,
            count($this->needs),
            $this->name,
        ];
    }

    public function reason(): string
    {
        return match (true) {
            $this->covered !== '' => 'no marginal coverage — already emitted by ' . $this->covered,
            $this->isStop() => 'a stop, not a cost — ' . substr($this->needs[0], 0, 64),
            default => ($this->findings === null ? '' : $this->findings . ' finding(s), ')
                . count($this->needs) . ' need(s), ' . $this->stepped . ' stepped over',
        };
    }
}
