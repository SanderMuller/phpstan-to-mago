<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * The two counters' parameter sets for one file, so a disagreement can be named rather than sized.
 *
 * {@see CoverageCorpus} answers how many. This answers which, and the difference matters: reasoning from a
 * total refuted three explanations for one file's delta in a row and identified none of them, while the set
 * named ten declarations in a single run.
 *
 * The original enumerates its set because it reports one finding per *untyped* parameter, so it is handed a
 * copy of the file with every parameter type stripped — which cannot change the total, since a declaration is
 * counted whether or not its parameters carry types. The port is asked for its own missing-parameter
 * locations over the same copy, which for a fully stripped file is every parameter it counts.
 */
final readonly class CoverageSetDiff
{
    public function __construct(
        private string $repositoryRoot,
        private string $consumerRoot,
        private string $configurationFile,
        private string $strippedDirectory,
        private string $strippedFile,
        private string $sandbox,
    ) {}

    /**
     * @return array{original: list<int>, port: array<int, list<string>>, onlyPort: array<int, list<string>>, onlyOriginal: list<int>}
     */
    public function sets(): array
    {
        $this->write();

        $original = $this->originalLines();
        $port = $this->portLines();

        $onlyPort = array_filter($port, static fn (array $_, int $line): bool => ! in_array($line, $original, true), ARRAY_FILTER_USE_BOTH);
        $onlyOriginal = array_values(array_filter($original, static fn (int $line): bool => ! isset($port[$line])));

        ksort($onlyPort);

        return ['original' => $original, 'port' => $port, 'onlyPort' => $onlyPort, 'onlyOriginal' => $onlyOriginal];
    }

    private function write(): void
    {
        foreach ([$this->repositoryRoot . '/vendor' => '/vendor', $this->repositoryRoot . '/vendor/bin/mago' => '/mago'] as $target => $link) {
            if (! is_link($this->sandbox . $link)) {
                symlink($target, $this->sandbox . $link);
            }
        }

        // Reports every parameter it counts, because the file it sees has no types left to find.
        file_put_contents($this->sandbox . '/plugin.php', <<<'PLUGIN'
            <?php

            declare(strict_types=1);

            namespace SetDiff;

            use Mago\Sdk\Analyzer\AfterAnalysisContext;
            use Mago\Sdk\Analyzer\AfterAnalysisHook;
            use Mago\Sdk\Analyzer\FileAnalysis;
            use Mago\Sdk\Analyzer\Plugin;
            use Mago\Sdk\Analyzer\PluginDefinition;
            use Mago\Sdk\Analyzer\PluginRegistry;
            use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

            final class ReportEachParameter implements AfterAnalysisHook, Plugin
            {
                public function getDefinition(): PluginDefinition
                {
                    return new PluginDefinition(identifier: 'setdiff/parameters', name: 'SetDiff', description: 'SetDiff');
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
                    $rows = [];
                    foreach (TypeCoverage::parameters($context)->missing as $location) {
                        $file = $context->analysis->getFile($location->file);
                        $text = $file instanceof FileAnalysis ? $file->getSourceFile()->getText($location->span) : '';
                        $rows[] = $location->span->start . "\t" . trim(str_replace("\n", ' ', $text));
                    }

                    sort($rows);
                    file_put_contents('parameters.txt', implode("\n", $rows));
                }
            }
            PLUGIN);

        file_put_contents($this->sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            ini_set('display_errors', 'stderr');

            use Mago\\Sdk\\Extension;
            use Mago\\Sdk\\Worker;
            use SetDiff\\ReportEachParameter;

            require '{$this->repositoryRoot}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'setdiff/parameters',
                name: 'SetDiff',
                version: '0.0.0',
                analyzerPlugins: [new ReportEachParameter()],
            )))->run();
            PHP);

        file_put_contents($this->sandbox . '/mago.toml', <<<TOML
            [source]
            paths = ["{$this->strippedDirectory}"]
            includes = ["{$this->consumerRoot}/vendor", "{$this->consumerRoot}/app"]

            [extension-hosts.setdiff]
            command = ["php", "worker.php"]
            TOML);

        file_put_contents($this->sandbox . '/phpstan-setdiff.neon', <<<NEON
            includes:
                - {$this->configurationFile}

            parameters:
                ignoreErrors!: []
                reportUnmatchedIgnoredErrors: false
                errorFormat: json
                paths!:
                    - {$this->strippedDirectory}
                type_coverage!:
                    declare: 0
                    return_type: 0
                    param_type: 100
                    property_type: 0
                    constant_type: 0
                    print_suggestions: false
                    return: 0
                    param: 100
                    property: 0
                    constant: 0
                    measure: false
            NEON);
    }

    /** @return list<int> */
    private function originalLines(): array
    {
        $output = $this->run([
            $this->consumerRoot . '/vendor/bin/phpstan',
            'analyse', '--no-progress', '--memory-limit=6G',
            '--configuration=' . $this->sandbox . '/phpstan-setdiff.neon',
        ], $this->consumerRoot);

        $start = strpos($output, '{');
        /** @var array{files?: array<string, array{messages: list<array{line: int, message: string}>}>}|null $decoded */
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("The real rule produced no JSON:\n" . substr($output, 0, 2000));
        }

        $lines = [];
        foreach ($decoded['files'] ?? [] as $messages) {
            foreach ($messages['messages'] as $message) {
                if (str_contains($message['message'], 'possible param types')) {
                    $lines[] = $message['line'];
                }
            }
        }

        sort($lines);

        return $lines;
    }

    /** @return array<int, list<string>> */
    private function portLines(): array
    {
        if (is_file($this->sandbox . '/parameters.txt')) {
            unlink($this->sandbox . '/parameters.txt');
        }

        $output = $this->run(['./mago', 'analyze', '--reporting-format', 'json'], $this->sandbox);
        if (! is_file($this->sandbox . '/parameters.txt')) {
            throw new RuntimeException("The port reported nothing:\n" . substr($output, 0, 2000));
        }

        // Byte offsets, because that is what a span is; turned into lines here so both sides read the same.
        $source = (string) file_get_contents($this->strippedFile);
        $rows = [];
        foreach (explode("\n", trim((string) file_get_contents($this->sandbox . '/parameters.txt'))) as $row) {
            if ($row === '') {
                continue;
            }

            [$offset, $text] = explode("\t", $row, 2);
            $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
            $rows[$line][] = $text;
        }

        ksort($rows);

        return $rows;
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
        proc_close($process);

        return $stdout === '' ? $stderr : $stdout;
    }
}
