<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * Where mago's tree holds a `&` and where it does not, one row per position with a by-value control beside it.
 *
 * The instrument behind one capability: a rule reading php-parser's `->byRef` needs a predicate per position,
 * and the predicate depends on whether the ampersand is in the tree. It is in three positions and not in two,
 * so a port written to one route is silently wrong for the other — and going quiet is the failure mode, not
 * an error.
 *
 * One mago run, no PHPStan side. The question is what one engine's tree contains, not whether two agree.
 */
final readonly class ReferenceKnowledge
{
    public function __construct(private string $fixture, private string $repositoryRoot) {}

    /**
     * `kind:name => row`, one per probed site, as the probe wrote it.
     *
     * @return array<string, string>
     */
    public function rows(): array
    {
        $sandbox = $this->prepare();
        $out = $sandbox . '/rows';
        if (is_file($out)) {
            unlink($out);
        }

        $process = proc_open(
            ['./mago', 'analyze', '--reporting-format', 'json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $sandbox,
            [...Subprocess::environment(), 'PROBE_OUT' => $out],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start mago');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $written = is_file($out) ? (string) file_get_contents($out) : '';
        if (trim($written) === '') {
            throw new RuntimeException("The probe wrote nothing, so it never looked:\n" . ($stdout === '' ? $stderr : $stdout));
        }

        $rows = [];
        foreach (explode("\n", trim($written)) as $line) {
            [$site, $rest] = array_pad(explode("\t", $line, 2), 2, '');
            $rows[$site] = $rest;
        }

        ksort($rows);

        return $rows;
    }

    private function prepare(): string
    {
        $sandbox = sys_get_temp_dir() . '/phpstan-to-mago-reference-' . getmypid();
        if (! is_dir($sandbox . '/src') && ! mkdir($sandbox . '/src', 0o777, true)) {
            throw new RuntimeException('Could not create ' . $sandbox);
        }

        copy($this->fixture . '/src/ReferenceShapes.php', $sandbox . '/src/ReferenceShapes.php');
        copy($this->fixture . '/ReferenceProbe.php', $sandbox . '/plugin.php');

        if (! is_link($sandbox . '/vendor')) {
            symlink($this->repositoryRoot . '/vendor', $sandbox . '/vendor');
        }

        if (! is_link($sandbox . '/mago')) {
            symlink($this->repositoryRoot . '/vendor/bin/mago', $sandbox . '/mago');
        }

        file_put_contents($sandbox . '/worker.php', sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                // A notice on stdout corrupts the extension frame — mago reads binary frames there.
                ini_set('display_errors', 'stderr');

                use ReferenceShapes\ReferenceProbe;
                use Mago\Sdk\Extension;
                use Mago\Sdk\Worker;

                require '%s/vendor/autoload.php';
                require __DIR__ . '/plugin.php';

                (new Worker(new Extension(
                    identifier: 'probe/reference',
                    name: 'ReferenceProbe',
                    version: '0.0.0',
                    analyzerPlugins: [new ReferenceProbe()],
                )))->run();
                PHP,
            $this->repositoryRoot,
        ));

        // No `includes`. The fixture names nothing outside itself, so there is nothing to resolve.
        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.probe]
            command = ["php", "worker.php"]
            TOML);

        return $sandbox;
    }
}
