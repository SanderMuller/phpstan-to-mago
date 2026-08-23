<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * One small project, counted by the real `type-coverage` rule and by the port, in measure mode.
 *
 * The parameter aggregate has no per-file translation to snapshot: PHPStan collects a fact per file and a
 * second rule reduces the collection, and the port answers the same question from Mago's tree and metadata.
 * So the only evidence worth having is the two numbers side by side — and on a project small enough that a
 * disagreement names its own cause.
 *
 * Measure mode on both sides, not a threshold. A threshold only speaks when the project is below it, so a
 * fully typed control goes silent on both sides and the comparison passes while measuring nothing. That
 * happened: a corpus 99.99% typed reported no number at all and read as agreement.
 */
final readonly class CoverageControl
{
    public function __construct(private string $project) {}

    /**
     * The real rule's total, and the port's, as `[phpstan, port]`.
     *
     * @return array{int, int}
     */
    public function totals(): array
    {
        $sandbox = $this->prepare();

        return [$this->phpstanTotal($sandbox), $this->portTotal($sandbox)];
    }

    /** Lays out a sandbox project: the sources, a worker, and a configuration for each tool. */
    private function prepare(): string
    {
        $root = dirname(__DIR__, 2);
        $sandbox = sys_get_temp_dir() . '/phpstan-to-mago-control-' . basename($this->project);
        // Cleared rather than merged into: a rename during development left both the old and the new copy
        // of a source file in a sandbox once, and the run that produced was reported as a disagreement.
        $stale = glob($sandbox . '/src/*.php');
        foreach ($stale === false ? [] : $stale as $file) {
            unlink($file);
        }

        if (! is_dir($sandbox . '/src') && ! mkdir($sandbox . '/src', 0o777, true)) {
            throw new RuntimeException('Could not create ' . $sandbox);
        }

        $sources = glob($this->project . '/src/*.php');
        foreach ($sources === false ? [] : $sources as $file) {
            copy($file, $sandbox . '/src/' . basename($file));
        }

        if (! is_link($sandbox . '/vendor')) {
            symlink($root . '/vendor', $sandbox . '/vendor');
        }

        if (! is_link($sandbox . '/mago')) {
            symlink($root . '/vendor/bin/mago', $sandbox . '/mago');
        }

        file_put_contents($sandbox . '/plugin.php', <<<'PLUGIN'
            <?php

            declare(strict_types=1);

            namespace Control;

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
                    return new PluginDefinition(identifier: 'control/measure', name: 'Measure', description: 'Measure');
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

        file_put_contents($sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            // A notice on stdout corrupts the extension frame — mago reads binary frames there.
            ini_set('display_errors', 'stderr');

            use Control\\MeasureParams;
            use Mago\\Sdk\\Extension;
            use Mago\\Sdk\\Worker;

            require '{$root}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'control/measure',
                name: 'Measure',
                version: '0.0.0',
                analyzerPlugins: [new MeasureParams()],
            )))->run();
            PHP);

        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.measure]
            command = ["php", "worker.php"]
            TOML);

        // A control may register PHPStan services of its own — the `reflection-extension` one registers a
        // `MethodsClassReflectionExtension`, because that is the only way to reproduce the cause of the
        // parameter aggregate's accepted divergence. Appended rather than always present, so every other
        // control is measured against a plain PHPStan.
        $services = is_file($this->project . '/services.neon')
            ? "\n" . file_get_contents($this->project . '/services.neon')
            : '';

        // The extension installer already registers type-coverage, so including its neon here would register
        // `MethodNodeAnalyser` twice and PHPStan would abort before analysing. Only the parameters are set,
        // and `measure` is what makes the rule report a count rather than a threshold breach.
        file_put_contents($sandbox . '/phpstan.neon', <<<'NEON'
            parameters:
                level: 0
                paths:
                    - src
                type_coverage:
                    measure: true
                    param_type: 0
                    return_type: 0
                    property_type: 0
                    constant_type: 0
                    declare: 0
            NEON . $services);

        return $sandbox;
    }

    private function phpstanTotal(string $sandbox): int
    {
        $output = $this->execute($sandbox, [
            dirname(__DIR__, 2) . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=phpstan.neon',
        ]);

        // "Param type coverage is 0.0 % out of 6 possible" — the measure-mode message.
        return preg_match('/Param type coverage is [\d.]+ % out of (\d+) possible/', $output, $matched) === 1
            ? (int) $matched[1]
            : throw new RuntimeException("The real rule reported no count:\n" . $output);
    }

    private function portTotal(string $sandbox): int
    {
        if (is_file($sandbox . '/measure.txt')) {
            unlink($sandbox . '/measure.txt');
        }

        $output = $this->execute($sandbox, ['./mago', 'analyze', '--reporting-format', 'json']);
        $measured = is_file($sandbox . '/measure.txt') ? file_get_contents($sandbox . '/measure.txt') : false;

        return is_string($measured) && $measured !== ''
            ? (int) $measured
            : throw new RuntimeException("The port reported no count:\n" . $output);
    }

    /** @param list<string> $command */
    private function execute(string $sandbox, array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $sandbox, Subprocess::environment());
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
}
