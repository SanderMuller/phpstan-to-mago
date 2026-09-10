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
     * @return array{bool, bool, int, int, int, int, string} the ordering key, in the order the axes decide
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
            $this->yieldTier(),
            -($this->findings ?? 0),
            $this->stepped,
            count($this->needs),
            $this->name,
        ];
    }

    /**
     * Measured-firing, then unmeasured, then measured-silent.
     *
     * **Absent from a yield report is not zero, and reading it as zero threw away what that report says.**
     * `run-refusal-yield.php` joins a rule to its findings by identifier and prints the caveat that a rule
     * spelling its identifier as a class constant or building it in a collaborator "cannot be joined", so its
     * absence is *unreadable* rather than silent. Four rules are in that state -- including
     * `ClassAttributeRequiresPhpVersionRule`, which ranked seventh on an `?? 0` that made unmeasured
     * indistinguishable from worthless.
     *
     * A measured zero is evidence the rule is not worth porting. An unmeasured rule is evidence of nothing,
     * so it sorts above the zeros and below anything known to fire.
     */
    /**
     * How the row opens: a count, or that no count could be joined.
     *
     * `yield unreadable` rather than a blank, because a blank reads as zero to exactly the reader the tier
     * above exists for. The rules in that state build their identifier in a collaborator or from a class
     * constant, so `run-refusal-yield.php` cannot join them -- and its own header says so.
     */
    private function yieldNote(): string
    {
        return match (true) {
            $this->findings === null => 'yield unreadable, ',
            default => $this->findings . ' finding(s), ',
        };
    }

    private function yieldTier(): int
    {
        return match (true) {
            $this->findings === null => 1,
            $this->findings > 0 => 0,
            default => 2,
        };
    }

    public function reason(): string
    {
        return match (true) {
            $this->covered !== '' => 'no marginal coverage — already emitted by ' . $this->covered,
            $this->isStop() => 'a stop, not a cost — ' . substr($this->needs[0], 0, 64),
            default => $this->yieldNote() . count($this->needs) . ' need(s), ' . $this->stepped
                . ' stepped over',
        };
    }
}
