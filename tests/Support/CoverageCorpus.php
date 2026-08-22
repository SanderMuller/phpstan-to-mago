<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The parameter aggregate, counted by the real `type-coverage` rule and by the port, over a consumer project.
 *
 * `Vocabulary::unverifiedAggregate('parameters')` withholds the mapping until a *corpus* differential agrees,
 * and quotes two numbers per corpus. Those numbers were produced by a script that lived outside the
 * repository, which makes a refusal message rest on a measurement nobody else can repeat — the same mistake
 * as reporting a suite green from a dependency set only one machine had. So the instrument lives here.
 *
 * Four details are load-bearing, and each was learnt by getting a wrong answer first:
 *
 * - **The consumer's vendor tree goes in mago's `includes`.** PHPStan has the consumer's autoloader, so its
 *   collector can ask whether a parent class declares a method. Without the same resolution context the
 *   port's LSP guard never fires and it over-counts — 501 against 285 on one package, which reads as a broken
 *   port and is a broken harness.
 * - **The consumer's `excludePaths` apply to mago too**, or the two tools are not looking at the same corpus.
 *   Honouring them moved one corpus from +769 to −63.
 * - **Paths come from the configuration, never the command line.**
 *   `ScopeConfigurationResolver::areFullPathsAnalysed()` compares PHPStan's analysed paths against its
 *   config's and the rule reports nothing when they differ. Two tools reporting nothing is not agreement.
 * - **Measure mode on both sides, not a threshold.** A threshold only speaks when the corpus is below it, so
 *   a corpus that is 99.99% typed goes silent and reads as "the port found nothing".
 *
 * Nothing is written into the consumer: the sandbox holds the plugin, the worker and both configurations.
 */
final readonly class CoverageCorpus
{
    /**
     * @param list<string> $paths absolute directories both tools analyse
     * @param list<string> $resolvable absolute directories both tools may resolve symbols in, analysed or not
     * @param list<string> $excludes absolute paths the consumer's own configuration excludes
     */
    public function __construct(
        private string $repositoryRoot,
        private string $consumerRoot,
        private string $configurationFile,
        private array $paths,
        private array $resolvable,
        private array $excludes,
        private string $sandbox,
    ) {}

    /**
     * The real rule's total, and the port's.
     *
     * @return array{original: int, port: int}
     */
    public function totals(): array
    {
        $this->write();

        return ['original' => $this->originalTotal(), 'port' => $this->portTotal()];
    }

    /** The `.php` files under the analysed paths, which is what "the corpus" means for both tools. */
    public function files(): int
    {
        $files = 0;
        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                $files += str_ends_with($path, '.php') ? 1 : 0;

                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $entry) {
                if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                    ++$files;
                }
            }
        }

        return $files;
    }

    private function write(): void
    {
        if (! is_dir($this->sandbox) && ! mkdir($this->sandbox, 0o777, true)) {
            throw new RuntimeException('Could not create ' . $this->sandbox);
        }

        foreach ([$this->repositoryRoot . '/vendor' => '/vendor', $this->repositoryRoot . '/vendor/bin/mago' => '/mago'] as $target => $link) {
            if (! is_link($this->sandbox . $link)) {
                symlink($target, $this->sandbox . $link);
            }
        }

        // Measure mode reports a count unconditionally, so the plugin does too — and writes it to a file
        // rather than reporting it, because a count is not a finding and has no span to sit on.
        file_put_contents($this->sandbox . '/plugin.php', <<<'PLUGIN'
            <?php

            declare(strict_types=1);

            namespace Corpus;

            use Mago\Sdk\Analyzer\AfterAnalysisContext;
            use Mago\Sdk\Analyzer\AfterAnalysisHook;
            use Mago\Sdk\Analyzer\Plugin;
            use Mago\Sdk\Analyzer\PluginDefinition;
            use Mago\Sdk\Analyzer\PluginRegistry;
            use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

            final class MeasureParams implements AfterAnalysisHook, Plugin
            {
                public function getDefinition(): PluginDefinition
                {
                    return new PluginDefinition(identifier: 'corpus/measure', name: 'Measure', description: 'Measure');
                }

                public function register(PluginRegistry $registry): void
                {
                    $registry->registerAfterAnalysisHook($this);
                }

                /** @return list<never> */
                public function getTargets(): array
                {
                    return [];
                }

                /** @return list<never> */
                public function getRequirements(): array
                {
                    return [];
                }

                public function afterAnalysis(AfterAnalysisContext $context): void
                {
                    file_put_contents('measure.txt', (string) TypeCoverage::parameters($context)->total);
                }
            }
            PLUGIN);

        file_put_contents($this->sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            // A notice on stdout corrupts the extension frame — mago reads binary frames there.
            ini_set('display_errors', 'stderr');

            use Corpus\\MeasureParams;
            use Mago\\Sdk\\Extension;
            use Mago\\Sdk\\Worker;

            require '{$this->repositoryRoot}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'corpus/measure',
                name: 'Measure',
                version: '0.0.0',
                analyzerPlugins: [new MeasureParams()],
            )))->run();
            PHP);

        // The consumer's whole source, not only the analysed subset. PHPStan resolves a parent class through
        // the consumer's autoloader whether or not it analyses the file that declares it, and mago resolves
        // only what it is given — so a run over one subdirectory left every parent declared elsewhere
        // unresolvable, the LSP guard silent, and the port over-counting. `app/Livewire/Portal` measured +1
        // against the real rule for exactly that reason, and its parent is in `app/Filament`. Bisecting was
        // therefore measuring the harness; with this it measures the port.
        //
        // Minus what is being analysed, because a path in both lists is treated as context only and the
        // analysis set comes out empty — "No files found to analyze", on a corpus of 2933 files.
        $context = array_values(array_diff($this->resolvable, $this->paths));

        file_put_contents($this->sandbox . '/mago.toml', sprintf(
            "[source]\npaths = [%s]\nincludes = [%s]\nexcludes = [%s]\n\n[extension-hosts.measure]\ncommand = [\"php\", \"worker.php\"]\n",
            $this->quoted($this->paths),
            $this->quoted([$this->consumerRoot . '/vendor', ...$context]),
            $this->quoted($this->expanded($this->excludes)),
        ));

        // Every key the schema declares, because `!` replaces the structure rather than merging into it and a
        // partial replacement fails validation on "The mandatory item 'parameters › type_coverage › param' is
        // missing". Both spellings are set: the newer versions read the short alias first.
        $listed = implode("\n", array_map(static fn (string $path): string => '        - ' . $path, $this->paths));
        // Both tools, or leave-one-out is asymmetric: the consumer's own exclusions arrive through the
        // included config, and anything this run adds has to reach the original as well as the port.
        //
        // The keyed form, with both keys, because `!` replaces the whole structure and the schema then wants
        // them: `analyse` is "not analysed, still scanned", which is what a left-out directory should be — it
        // stays resolvable for both tools, exactly as `includes` keeps it resolvable for mago.
        $excluded = implode("\n", array_map(static fn (string $path): string => '            - ' . $path, $this->excludes));
        file_put_contents($this->sandbox . '/phpstan-coverage.neon', <<<NEON
            includes:
                - {$this->configurationFile}

            parameters:
                ignoreErrors!: []
                reportUnmatchedIgnoredErrors: false
                errorFormat: json
                paths!:
            {$listed}
                excludePaths!:
                    analyse:
            {$excluded}
                    analyseAndScan: []
                type_coverage!:
                    declare: 0
                    return_type: 0
                    param_type: 0
                    property_type: 0
                    constant_type: 0
                    print_suggestions: false
                    return: 0
                    param: 0
                    property: 0
                    constant: 0
                    measure: true
            NEON);
    }

    private function originalTotal(): int
    {
        // The consumer's own phpstan, not this repository's: the consumer's config declares parameters whose
        // schema comes from extensions it installs, and this repository's phpstan rejects such a config
        // outright with "Unexpected item 'parameters › checkOctaneCompatibility'" — not a number and not a
        // zero. Run from the consumer root for the same reason: its config names services out of its own
        // `autoload-dev`, which is only autoloadable from there.
        $output = $this->run([
            $this->consumerRoot . '/vendor/bin/phpstan',
            'analyse', '--no-progress', '--memory-limit=6G', '-v',
            '--configuration=' . $this->sandbox . '/phpstan-coverage.neon',
        ], $this->consumerRoot);

        return preg_match('/Param type coverage is [\d.]+ % out of (\d+) possible/', $output, $matched) === 1
            ? (int) $matched[1]
            : throw new RuntimeException("The real rule reported no count:\n" . substr($output, 0, 2000));
    }

    private function portTotal(): int
    {
        if (is_file($this->sandbox . '/measure.txt')) {
            unlink($this->sandbox . '/measure.txt');
        }

        $output = $this->run(['./mago', 'analyze', '--reporting-format', 'json'], $this->sandbox);
        $measured = is_file($this->sandbox . '/measure.txt')
            ? (string) file_get_contents($this->sandbox . '/measure.txt')
            : '';

        return $measured === ''
            ? throw new RuntimeException("The port reported no count:\n" . substr($output, 0, 2000))
            : (int) $measured;
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Both tools exit non-zero when they find something, which is the expected outcome here.
        proc_close($process);

        return $stdout === '' ? $stderr : $stdout;
    }

    /**
     * An excluded directory and everything under it, which is what mago wants where PHPStan takes the prefix.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function expanded(array $paths): array
    {
        $expanded = [];
        foreach ($paths as $path) {
            $expanded[] = $path;
            $expanded[] = $path . '/**';
        }

        return $expanded;
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => '"' . $value . '"', $values));
    }
}
