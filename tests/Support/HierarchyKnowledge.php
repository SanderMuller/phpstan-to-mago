<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * What the codebase can say about an ancestry, for a class it fully understands and for one it does not.
 *
 * The instrument behind one decision: PHPStan's `TrinaryLogic` has no Mago equivalent, so a port of any rule
 * reading `->isSuperTypeOf(..)->yes()` or `->no()` has to answer "what does it say when Mago cannot tell". That
 * only matters if *cannot tell* is observable, and Mago skips the body of a class whose parent it cannot
 * resolve — so the state might never reach a hook at all.
 *
 * One mago run, no PHPStan side. There is nothing to compare against here: the question is what one engine
 * knows, not whether two engines agree.
 */
final readonly class HierarchyKnowledge
{
    public function __construct(private string $fixture, private string $repositoryRoot) {}

    /**
     * `callee => row`, one per probed call, as the probe wrote it.
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
            [$callee, $rest] = array_pad(explode("\t", $line, 2), 2, '');
            $rows[$callee] = $rest;
        }

        ksort($rows);

        return $rows;
    }

    private function prepare(): string
    {
        $sandbox = sys_get_temp_dir() . '/phpstan-to-mago-hierarchy';
        if (! is_dir($sandbox . '/src') && ! mkdir($sandbox . '/src', 0o777, true)) {
            throw new RuntimeException('Could not create ' . $sandbox);
        }

        copy($this->fixture . '/src/Hierarchy.php', $sandbox . '/src/Hierarchy.php');
        copy($this->fixture . '/HierarchyProbe.php', $sandbox . '/plugin.php');

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

                use HierarchyShapes\HierarchyProbe;
                use Mago\Sdk\Extension;
                use Mago\Sdk\Worker;

                require '%s/vendor/autoload.php';
                require __DIR__ . '/plugin.php';

                (new Worker(new Extension(
                    identifier: 'probe/hierarchy',
                    name: 'HierarchyProbe',
                    version: '0.0.0',
                    analyzerPlugins: [new HierarchyProbe()],
                )))->run();
                PHP,
            $this->repositoryRoot,
        ));

        // No `includes`, deliberately. The unresolvable parent has to stay unresolvable, and adding a
        // resolution path for anything would risk resolving it by accident.
        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.probe]
            command = ["php", "worker.php"]
            TOML);

        return $sandbox;
    }
}
