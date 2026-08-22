<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * The command line flags, separated from the paths.
 *
 * Parsing lives here rather than in `Cli` because the target is not a detail: a rule can render as Rust
 * and be refused as PHP, so a count belongs to a target and reading a count for the wrong one looks like
 * a bug in the tool. Keeping the flags in one place is what makes "which target is this" answerable.
 *
 * `--from-config` is the same argument applied to the denominator: a count over files found on disk and a
 * count over rules a project registered are different numbers, and the second is the one that describes the
 * project. See `RegisteredRules`.
 */
final readonly class Options
{
    /**
     * The PHP target is the product: an ordinary composer library that runs on the Mago SDK. The two Rust
     * targets only run compiled into Mago's own crate, so they are not what anyone porting rules wants.
     *
     * @var list<string>
     */
    public const array TARGETS = ['php', 'analyzer', 'linter'];

    /**
     * @param list<string> $paths
     */
    private function __construct(
        public array $paths,
        public string $target,
        public bool $survey,
        public ?string $examplesDir,
        public bool $unverified = false,
        public ?string $fromConfig = null,
    ) {}

    /**
     * @param list<string> $argv
     *
     * @throws Refusal when a target is named that does not exist, rather than falling back to a default
     */
    public static function parse(array $argv): self
    {
        $paths = [];
        $target = Transpiler::$target;
        $survey = false;
        $examplesDir = null;
        $unverified = false;
        $fromConfig = null;

        foreach ($argv as $argument) {
            if ($argument === '--unverified' || $argument === '--unverified-aggregates') {
                $unverified = true;
            } elseif ($argument === '--survey') {
                $survey = true;
            } elseif (str_starts_with($argument, '--target=')) {
                $target = self::target(substr($argument, strlen('--target=')));
            } elseif (str_starts_with($argument, '--from-config=')) {
                $fromConfig = substr($argument, strlen('--from-config='));
            } elseif (str_starts_with($argument, '--examples=')) {
                $examplesDir = substr($argument, strlen('--examples='));
            } else {
                $paths[] = $argument;
            }
        }

        return new self($paths, $target, $survey, $examplesDir, $unverified, $fromConfig);
    }

    public function outDir(string $outRoot): string
    {
        return $outRoot . match ($this->target) {
            'linter' => '/generated-lint',
            'php' => '/generated-php',
            default => '/generated',
        };
    }

    private static function target(string $target): string
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new Refusal('unknown target "' . $target . '", expected one of: ' . implode(', ', self::TARGETS));
        }

        return $target;
    }
}
