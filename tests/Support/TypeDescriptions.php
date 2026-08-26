<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * One file of type shapes, rendered by PHPStan and by a Mago plugin, keyed by the call that probed each.
 *
 * Keyed by callee rather than by line: Mago's spans are 0-based where PHPStan's are 1-based, and this project
 * has already paid once for importing that mismatch into a comparison.
 *
 * Both tools run through {@see Subprocess::environment()}, so PHPStan's own output arrives rather than a tool
 * wrapper's summary of it. The Mago plugin declares `FileAnalysisRequirement::ExpressionTypes`, without which
 * it would report nothing and the measurement would read as "Mago has no types".
 */
final readonly class TypeDescriptions
{
    public function __construct(private string $fixture, private string $repositoryRoot) {}

    /**
     * `callee => [phpstan, mago, recoverable]` for every probed shape.
     *
     * The third column is what Mago's atomics still hold of whatever its rendering dropped, and it is empty on
     * the PHPStan side because PHPStan's rendering drops nothing being measured here.
     *
     * The fourth is what {@see Describe} renders from those atomics, which is the column the port ships.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public function rendered(): array
    {
        $sandbox = $this->prepare();

        $phpstan = $this->rows($sandbox, [
            $this->repositoryRoot . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--configuration=phpstan.neon',
            '--autoload-file=' . $sandbox . '/bootstrap.php',
        ]);

        $mago = $this->rows($sandbox, ['./mago', 'analyze', '--reporting-format', 'json']);

        $pairs = [];
        foreach ($phpstan as $callee => $described) {
            [$rendered, $recoverable, $describe] = array_pad(explode("\t", $mago[$callee] ?? '<not reached>', 3), 3, '');
            $pairs[$callee] = [$described, $rendered, $recoverable, $describe];
        }

        foreach (array_diff_key($mago, $phpstan) as $callee => $row) {
            [$rendered, $recoverable, $describe] = array_pad(explode("\t", $row, 3), 3, '');
            $pairs[$callee] = ['<not reached>', $rendered, $recoverable, $describe];
        }

        ksort($pairs);

        return $pairs;
    }

    /**
     * One run's rows, read from the file it wrote rather than from its output.
     *
     * @param list<string> $command
     *
     * @return array<string, string>
     */
    private function rows(string $sandbox, array $command): array
    {
        $out = $sandbox . '/' . basename($command[0]) . '.rows';
        if (is_file($out)) {
            unlink($out);
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $sandbox,
            [...Subprocess::environment(), 'PROBE_OUT' => $out],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $written = is_file($out) ? (string) file_get_contents($out) : '';
        if (trim($written) === '') {
            throw new RuntimeException(
                sprintf("%s probed nothing, so it never looked:\n%s", basename($command[0]), $stdout === '' ? $stderr : $stdout),
            );
        }

        $rows = [];
        foreach (explode("\n", trim($written)) as $line) {
            [$callee, $rendered] = array_pad(explode("\t", $line, 2), 2, '');
            $rows[$callee] = $rendered;
        }

        return $rows;
    }

    /** Lays out a sandbox: the shapes, a worker and a plugin for mago, a rule and a config for PHPStan. */
    private function prepare(): string
    {
        $sandbox = sys_get_temp_dir() . '/phpstan-to-mago-types-' . getmypid();
        if (! is_dir($sandbox . '/src') && ! mkdir($sandbox . '/src', 0o777, true)) {
            throw new RuntimeException('Could not create ' . $sandbox);
        }

        // PHPStan's result cache keys on the analysed files and the configuration, and the *rule* is neither —
        // so a second run served the first run's findings without the probe rule ever executing, and the
        // harness reported that the tool "probed nothing". Cleared on every layout, the way the fires-gate
        // sandbox does for the same reason.
        $cache = glob($sandbox . '/phpstan-tmp/*');
        foreach ($cache === false ? [] : $cache as $cached) {
            if (is_file($cached)) {
                unlink($cached);
            }
        }

        copy($this->fixture . '/src/Subject.php', $sandbox . '/src/Subject.php');
        copy($this->fixture . '/PhpstanTypeProbe.php', $sandbox . '/PhpstanTypeProbe.php');
        copy($this->fixture . '/MagoTypeProbe.php', $sandbox . '/plugin.php');

        if (! is_link($sandbox . '/vendor')) {
            symlink($this->repositoryRoot . '/vendor', $sandbox . '/vendor');
        }

        if (! is_link($sandbox . '/mago')) {
            symlink($this->repositoryRoot . '/vendor/bin/mago', $sandbox . '/mago');
        }

        file_put_contents($sandbox . '/bootstrap.php', sprintf(
            "<?php\n\nrequire '%s/vendor/autoload.php';\nrequire __DIR__ . '/PhpstanTypeProbe.php';\n",
            $this->repositoryRoot,
        ));

        file_put_contents($sandbox . '/worker.php', sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                // A notice on stdout corrupts the extension frame — mago reads binary frames there.
                ini_set('display_errors', 'stderr');

                use Mago\Sdk\Extension;
                use Mago\Sdk\Worker;
                use TypeShapes\MagoTypeProbe;

                require '%s/vendor/autoload.php';
                require __DIR__ . '/plugin.php';

                (new Worker(new Extension(
                    identifier: 'probe/type',
                    name: 'MagoTypeProbe',
                    version: '0.0.0',
                    analyzerPlugins: [new MagoTypeProbe()],
                )))->run();
                PHP,
            $this->repositoryRoot,
        ));

        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.probe]
            command = ["php", "worker.php"]
            TOML);

        // Level 8 so PHPStan infers from the declared types rather than answering `mixed` everywhere, and its
        // own findings are irrelevant here — the rows are written from the rule, not read from the report.
        file_put_contents($sandbox . '/phpstan.neon', <<<'NEON'
            parameters:
                bootstrapFiles:
                    - bootstrap.php
                tmpDir: phpstan-tmp
                level: 8
                paths:
                    - src
            services:
                -
                    class: TypeShapes\PhpstanTypeProbe
                    tags: [phpstan.rules.rule]
            NEON);

        return $sandbox;
    }
}
