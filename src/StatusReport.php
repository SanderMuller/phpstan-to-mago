<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * What one project's installed rule packages do on mago, as a page.
 *
 * The census pins *this* repository's corpus so an upstream release arrives as a readable diff. This
 * answers the other question, for a consumer: of the rules already installed here, which ones run on mago,
 * and what stops the rest. Same four-way split, same reasons, different reader — so it shares
 * {@see PackageCoverage} and owns only its own prose.
 *
 * Three things this prints that the census deliberately does not:
 *
 * - **Package versions.** The census leaves them out because a bump that changes no translation would
 *   otherwise fire the drift alarm, and an alarm that fires on every routine bump is one nobody reads. A
 *   status page is a point-in-time report about one install, so the version is the first thing a reader
 *   needs to know it is about theirs.
 * - **Both denominators.** `ships` next to `registers`, because a reader who counts the classes in
 *   `vendor/` will get the larger number and needs to see why the coverage figure uses the smaller one.
 * - **The refusals in full.** A coverage figure nobody can check is a claim, not a report. Partial coverage
 *   is only worth shipping if the gap is legible.
 */
final readonly class StatusReport
{
    /** @param list<PackageCoverage> $packages */
    private function __construct(
        public string $target,
        public array $packages,
        /** @var array<string, string> */
        public array $versions,
        /**
         * When this page was made, because a static snapshot with no date cannot be told from a current one.
         */
        public string $generatedAt,
        /** The command that rebuilds it, so the page can hand it over rather than pretend to re-scan. */
        public string $command,
    ) {}

    public static function forProject(string $projectRoot, string $target, string $outRoot = '.'): self
    {
        $packages = [];
        $versions = [];
        foreach (InstalledRulePackages::discover($projectRoot) as $installed) {
            $packages[] = PackageCoverage::forPackage($installed->name, $installed->root);
            $versions[$installed->name] = $installed->version;
        }

        return new self(
            $target,
            $packages,
            $versions,
            date('Y-m-d H:i'),
            sprintf('vendor/bin/phpstan-to-mago --status --out=%s', $outRoot),
        );
    }

    public function emitted(): int
    {
        return array_sum(array_map(static fn (PackageCoverage $p): int => $p->emitted(), $this->packages));
    }

    public function portable(): int
    {
        return array_sum(array_map(static fn (PackageCoverage $p): int => $p->portable(), $this->packages));
    }

    /**
     * The packages with at least one rule that runs, which are the ones a worker registers.
     *
     * @return list<PackageCoverage>
     */
    public function withCoverage(): array
    {
        return array_values(array_filter($this->packages, static fn (PackageCoverage $p): bool => $p->emitted() > 0));
    }

    /** @return list<RuleOutcome> every rule that runs, across every package */
    public function running(): array
    {
        $running = [];
        foreach ($this->packages as $package) {
            foreach ($package->registered() as $outcome) {
                if ($outcome->emitted()) {
                    $running[] = $outcome;
                }
            }
        }

        return $running;
    }
}
