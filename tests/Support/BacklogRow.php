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

    /** @return array{bool, bool, int, int, string} the ordering key, in the order the axes decide */
    public function rank(): array
    {
        return [$this->covered !== '', $this->isStop(), $this->stepped, count($this->needs), $this->name];
    }

    public function reason(): string
    {
        return match (true) {
            $this->covered !== '' => 'no marginal coverage — already emitted by ' . $this->covered,
            $this->isStop() => 'a stop, not a cost — ' . substr($this->needs[0], 0, 64),
            default => count($this->needs) . ' need(s), ' . $this->stepped . ' stepped over',
        };
    }
}
