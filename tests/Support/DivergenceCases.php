<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * Every recorded divergence, run as one batch and written to one file.
 *
 * A divergence found by a corpus run lives in `VERIFICATION.md` as prose and nowhere else, so it cannot be
 * re-checked later. Two have already been lost that way: `Benchmark.php:27`'s cause is marked permanently
 * unknown because the Laravel version it was found against was never captured, and the same finding appears
 * as `QueueFake.php:214` in one entry and `:167` in a later run. A case here is the same finding pinned to
 * code this repository owns, so it reproduces for as long as the case exists.
 *
 * **A case records what the two engines do, whichever that is.** Agreement and divergence are both results,
 * and the file goes red on any change to either — the same choice `TraitMethodHookDivergesTest` makes and
 * states: a characterisation, not a gate. Green means "unchanged", never "agreed", which matters because a
 * closed divergence reopening is exactly as interesting as an open one closing.
 *
 * **One boot, not one per case.** A case costs about thirteen seconds when it starts its own `mago` and its
 * own PHPStan, which does not survive ten cases inside a suite that already takes minutes. So every subject
 * is analysed in one run of each engine, and findings are attributed back by which case directory the file
 * sits in.
 *
 * That trade has a cost this repository has measured from both directions, and it is why cases must not
 * interfere: a trait with no using class in scope changes what PHPStan reports about it — 19 of 28 recorded
 * divergences are that effect — and narrowing a corpus to one directory turned every finding into
 * `trait.unused`. So each case gets its own namespace, and {@see self::refuseCollidingNamespaces()} refuses
 * before either engine starts rather than letting one case quietly change another's findings.
 */
final class DivergenceCases
{
    private const string WORKER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // stdout belongs to mago's binary frames. A notice written there arrives as
        // `invalid extension frame magic`, and nothing in the failure names the cause.
        ini_set('display_errors', 'stderr');

        require '{autoload}';
        {requires}

        (new Mago\Sdk\Worker(new Mago\Sdk\Extension(
            identifier: 'divergence/cases',
            name: 'Divergence cases',
            version: '0.0.0',
            analyzerPlugins: [{plugins}],
        )))->run();
        PHP;

    private const string MAGO_CONFIG = <<<'TOML'
        [source]
        paths = ["cases"]

        [extension-hosts.cases]
        command = ["php", "worker.php"]
        TOML;

    private const string PHPSTAN_CONFIG = <<<'NEON'
        parameters:
            # Inside the sandbox, because PHPStan's result cache keys on the analysed files and the
            # configuration and not on the rule — so a changed rule with unchanged subjects serves findings
            # from the previous version of itself.
            tmpDir: phpstan-tmp
            level: 0
            reportUnmatchedIgnoredErrors: false
            paths:
                - cases
        services:
        {services}
        NEON;

    /** @param non-empty-string $root */
    public function __construct(private readonly string $root) {}

    /**
     * The case directories, by name.
     *
     * @return array<string, array{path: string, rule: string, namespace: string}>
     */
    public function cases(): array
    {
        $directories = glob($this->root . '/tests/Fixtures/Divergence/*', GLOB_ONLYDIR);
        $cases = [];
        foreach ($directories === false ? [] : $directories as $path) {
            $cases[basename($path)] = [
                'path' => $path,
                'rule' => $this->ruleOf($path),
                'namespace' => $this->namespaceOf($path),
            ];
        }

        ksort($cases);

        return $cases;
    }

    /**
     * The rule a case runs, from its `case.neon`.
     *
     * Read with a line match rather than a neon parser: the file holds one key and a parser dependency for
     * one key is a dependency to keep working. A malformed file is a refusal naming the case, because a case
     * that silently runs no rule records "no findings" and reads as agreement.
     */
    private function ruleOf(string $path): string
    {
        $file = $path . '/case.neon';
        if (! is_file($file)) {
            throw new RuntimeException(basename($path) . ' has no case.neon, so nothing says which rule it runs');
        }

        if (preg_match('/^rule:\s*(\S+)\s*$/m', (string) file_get_contents($file), $match) !== 1) {
            throw new RuntimeException(basename($path) . "'s case.neon declares no `rule:` line");
        }

        $rule = ltrim($match[1], '\\');
        if (! class_exists($rule)) {
            throw new RuntimeException(basename($path) . ' names a rule that does not exist: ' . $rule);
        }

        return $rule;
    }

    /**
     * The namespace a case's subjects declare, which is what keeps one case out of another's analysis.
     */
    private function namespaceOf(string $path): string
    {
        $subjects = glob($path . '/subject/*.php');
        foreach ($subjects === false ? [] : $subjects as $subject) {
            if (preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($subject), $match) === 1) {
                return trim($match[1]);
            }
        }

        throw new RuntimeException(basename($path) . ' has no subject declaring a namespace');
    }

    /**
     * Refuse two cases sharing a namespace, before either engine starts.
     *
     * @param array<string, array{path: string, rule: string, namespace: string}> $cases
     */
    public function refuseCollidingNamespaces(array $cases): void
    {
        $seen = [];
        foreach ($cases as $name => $case) {
            $seen[$case['namespace']][] = $name;
        }

        foreach ($seen as $namespace => $names) {
            if (count($names) > 1) {
                throw new RuntimeException(
                    'These cases share the namespace ' . $namespace . ', so each one changes what the other '
                    . 'reports: ' . implode(', ', $names),
                );
            }
        }
    }

    /**
     * One sandbox holding every case, with every rule transpiled into one worker.
     *
     * @param array<string, array{path: string, rule: string, namespace: string}> $cases
     */
    public function sandbox(array $cases): string
    {
        $this->refuseCollidingNamespaces($cases);

        $sandbox = sys_get_temp_dir() . '/divergence-' . bin2hex(random_bytes(6));
        mkdir($sandbox . '/cases', 0o777, true);
        mkdir($sandbox . '/plugins', 0o777, true);
        symlink($this->root . '/vendor', $sandbox . '/vendor');

        // Keyed by rule, not by case. Two cases may name one rule — three of the planned ones name
        // `NoDynamicNameRule` — and a plugin file per case would `require` two files both declaring
        // `\Transpiled\<Rule>`, which is a fatal on the second, while PHPStan would register the class
        // twice and report each finding twice. Attribution is by directory, so one plugin serves every case
        // that names it and nothing else moves.
        $requires = [];
        $plugins = [];
        $services = [];
        foreach ($cases as $name => $case) {
            $this->copyInto($case['path'] . '/subject', $sandbox . '/cases/' . $name);

            if (isset($requires[$case['rule']])) {
                continue;
            }

            $short = $this->transpile($case['rule'], $sandbox, $name);
            $requires[$case['rule']] = "require __DIR__ . '/plugins/{$short}.php';";
            $plugins[$case['rule']] = "new \\Transpiled\\{$short}()";
            $services[$case['rule']] = '    -' . PHP_EOL . '        class: ' . $case['rule'] . PHP_EOL
                . '        tags: [phpstan.rules.rule]';
        }

        file_put_contents($sandbox . '/worker.php', strtr(self::WORKER, [
            '{autoload}' => $this->root . '/vendor/autoload.php',
            '{requires}' => implode(PHP_EOL, $requires),
            '{plugins}' => implode(', ', $plugins),
        ]));
        file_put_contents($sandbox . '/mago.toml', self::MAGO_CONFIG);
        file_put_contents($sandbox . '/phpstan.neon', strtr(self::PHPSTAN_CONFIG, [
            '{services}' => implode(PHP_EOL, $services),
        ]));

        return $sandbox;
    }

    /**
     * Transpile one rule into the sandbox, refusing rather than recording a case whose rule does not emit.
     *
     * A case naming a refused rule would record nothing on the port side, which reads as a divergence rather
     * than as a broken case — the distinction this whole file exists to keep.
     */
    private function transpile(string $rule, string $sandbox, string $case): string
    {
        /** @var class-string $rule */
        $file = (new \ReflectionClass($rule))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException($case . ' names a rule with no file: ' . $rule);
        }

        $out = $sandbox . '/gen-' . $case;
        $this->capture(
            ['php', $this->root . '/bin/phpstan-to-mago', '--target=php', '--out=' . $out, $file],
            $this->root,
        );

        $short = basename($file, '.php');
        $plugin = $out . '/generated-php/' . $short . '.php';
        if (! is_file($plugin)) {
            throw new RuntimeException(
                $case . ' names a rule the transpiler refuses, so the port side would record nothing and read '
                . 'as a divergence: ' . $rule,
            );
        }

        copy($plugin, $sandbox . '/plugins/' . $short . '.php');

        return $short;
    }

    private function copyInto(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $files = glob($from . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            copy($file, $to . '/' . basename($file));
        }
    }

    /**
     * What each engine reports, per case, from one run apiece.
     *
     * Attributed by which case directory a finding's file sits in, and the identifier is recorded rather
     * than filtered. A rule firing on another case's subject is interference, and interference that is
     * visible in the record is interference someone can act on — filtering it out would hide exactly the
     * failure batching risks.
     *
     * @param array<string, array{path: string, rule: string, namespace: string}> $cases
     *
     * @return array<string, array{port: list<string>, original: list<string>}>
     */
    public function findings(array $cases, string $sandbox): array
    {
        $port = $this->magoFindings($sandbox);
        $original = $this->phpstanFindings($sandbox);

        $rows = [];
        foreach ($cases as $name => $case) {
            $rows[$name] = [
                'port' => $this->forCase($port, $name),
                'original' => $this->forCase($original, $name),
            ];
        }

        return $rows;
    }

    /** @return list<array{file: string, line: int, identifier: string}> */
    private function magoFindings(string $sandbox): array
    {
        $output = $this->capture([$this->root . '/vendor/bin/mago', 'analyze', '--reporting-format=json'], $sandbox);

        /** @var array{issues?: list<array{code?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}, start?: array{line?: int}}}>}>} $decoded */
        $decoded = json_decode($output, true) ?? [];

        $found = [];
        foreach ($decoded['issues'] ?? [] as $issue) {
            $code = $issue['code'] ?? '';
            if (! str_starts_with($code, 'transpiled/')) {
                continue;
            }

            $span = $issue['annotations'][0]['span'] ?? [];
            $found[] = [
                'file' => (string) ($span['file_id']['name'] ?? ''),
                // Plus one: mago's JSON counts lines from zero and its own listing from one. The same
                // correction `CorpusDifferential` makes at the same field, for the same reason.
                'line' => ((int) ($span['start']['line'] ?? 0)) + 1,
                'identifier' => substr($code, (int) strrpos($code, '/') + 1),
            ];
        }

        return $found;
    }

    /** @return list<array{file: string, line: int, identifier: string}> */
    private function phpstanFindings(string $sandbox): array
    {
        $output = $this->capture(
            [$this->root . '/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--no-progress', '--error-format=json'],
            $sandbox,
        );

        /** @var array{files?: array<string, array{messages: list<array{line: int, identifier?: string}>}>} $decoded */
        $decoded = json_decode($output, true) ?? [];

        $found = [];
        foreach ($decoded['files'] ?? [] as $file => $info) {
            foreach ($info['messages'] as $message) {
                $found[] = [
                    'file' => $file,
                    'line' => $message['line'],
                    'identifier' => $message['identifier'] ?? 'no-identifier',
                ];
            }
        }

        return $found;
    }

    /**
     * @param list<array{file: string, line: int, identifier: string}> $findings
     *
     * @return list<string>
     */
    private function forCase(array $findings, string $case): array
    {
        $mine = [];
        foreach ($findings as $finding) {
            // No leading slash: mago's JSON reports a path relative to the sandbox and PHPStan's an
            // absolute one. Requiring `/cases/` matched only PHPStan, so every case recorded the port as
            // silent and read as a divergence — caught because the case carries an unguarded control that
            // both engines must report, which a false silence turns red.
            if (! str_contains($finding['file'], 'cases/' . $case . '/')) {
                continue;
            }

            $mine[] = sprintf('%s:%d  %s', basename($finding['file']), $finding['line'], $finding['identifier']);
        }

        sort($mine);

        return $mine;
    }

    /** @param list<string> $command */
    private function capture(array $command, string $cwd): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, Subprocess::environment());
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

    /** The versions this run was recorded against, read rather than assumed. */
    public function versions(): string
    {
        $mago = trim($this->capture([$this->root . '/vendor/bin/mago', '--version'], $this->root));
        $phpstan = trim($this->capture([$this->root . '/vendor/bin/phpstan', '--version'], $this->root));

        return sprintf(
            '%s, %s',
            $mago,
            (string) preg_replace('/^(PHPStan)[^\d]*([\d.]+).*$/s', '$1 $2', $phpstan),
        );
    }
}
