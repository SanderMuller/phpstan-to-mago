<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Transpiler;
use SplFileInfo;
use Throwable;

/**
 * The corpus differential: every emitted plugin and every rule it came from, over one consumer project.
 *
 * The per-rule {@see FiresGate} proves a plugin fires on a pair written for it. This asks the larger
 * question — do the two engines make the same decisions on code nobody wrote for either of them — and it
 * is the only place a port that is systematically *narrower* shows up, because a narrow port passes its own
 * example and then quietly reports less on real code.
 *
 * Three things here are load-bearing rather than convenient:
 *
 * - **The consumer's own PHPStan config is included, with its baseline replaced by nothing.** A baselined
 *   violation is a real finding the original would report, so leaving the baseline in turns each one into a
 *   fake only-port disagreement — in the direction that reads as the port being too wide.
 * - **Nothing is copied.** Both tools read the consumer in place, so there is no second corpus to drift, and
 *   no consumer source is written into this repository.
 * - **Findings are compared as `(identifier, file, line)` triples**, never as counts. Equal totals over
 *   different sites is a failure that looks like a success.
 *
 * Two rules can report under one identifier — `hihaho/phpstan-rules` has such a pair — so attribution is by
 * identifier, and rules sharing one are compared as a single bucket rather than split on a guess.
 */
final class CorpusDifferential
{
    private const string WORKER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // A notice on stdout corrupts the extension frame — mago reads binary frames there, and the first
        // bytes of a deprecation message arrive as `invalid extension frame magic`. One deprecated
        // function in a vendored dependency turned 284 passing tests into 107 errors under
        // `--prefer-lowest`, and nothing in the failure named the cause.
        ini_set('display_errors', 'stderr');

        use Mago\Sdk\Extension;
        use Mago\Sdk\Worker;

        require '{autoload}';

        foreach (glob(__DIR__ . '/plugins/*.php') as $plugin) {
            require $plugin;
        }

        (new Worker(new Extension(
            identifier: 'differential/corpus',
            name: 'Every transpiled rule',
            version: '0.0.0',
            analyzerPlugins: [{plugins}],
        )))->run();
        PHP;

    /** @var list<array{name: string, class: string, identifiers: list<string>, register: bool}> */
    private array $emitted = [];

    /** @var list<string> configured packages the consumer does not have */
    private array $skipped = [];

    /** @var list<array{name: string, reason: string}> */
    private array $refused = [];

    /**
     * @param list<string> $packages vendor-relative package directories to transpile
     * @param list<string> $paths consumer-relative directories both tools analyse
     * @param list<string> $excludes consumer-relative paths its own configuration excludes from analysis
     */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $consumerRoot,
        private readonly string $sandbox,
        private readonly array $packages,
        private readonly array $paths,
        private readonly array $excludes = [],
        /**
         * Container parameters forced to a value on both sides, for asking what a corpus would report at a
         * configuration it does not run.
         *
         * @var array<string, bool>
         */
        private readonly array $overrides = [],
    ) {}

    /**
     * The consumer's own PHPStan configuration.
     *
     * `phpstan.neon` when it exists, `phpstan.neon.dist` otherwise. Both spellings are ordinary — a project
     * that gitignores the first and commits the second is the common Laravel skeleton — and hardcoding the
     * first made every such project unmeasurable, which is most of the ones on hand.
     */
    public static function configurationOf(string $consumerRoot): string
    {
        $candidate = $consumerRoot . '/phpstan.neon';

        return is_file($candidate) ? $candidate : $consumerRoot . '/phpstan.neon.dist';
    }

    /**
     * Configured rule packages this consumer does not install.
     *
     * @return list<string>
     */
    public function packagesNotInstalled(): array
    {
        return array_values(array_unique($this->skipped));
    }

    /**
     * The rules this harness did **not** register itself, because their constructor takes a configured value.
     *
     * Printed next to the counts, because a count belongs to its configuration. Such a rule may still be
     * running — the package's own `extension.neon` registers it with real values, and the consumer's config
     * includes that — or it may be running nowhere, in which case every `only-port` finding under its
     * identifiers is an artefact rather than a disagreement. Nothing here can tell those apart, and reading a
     * report that did not say so produced exactly the wrong conclusion once.
     *
     * @return list<string>
     */
    public function notRegisteredHere(): array
    {
        $names = [];
        foreach ($this->emitted as $rule) {
            if (! $rule['register']) {
                $names[] = $rule['name'];
            }
        }

        return $names;
    }

    /**
     * Transpiles every rule in the configured packages, writing the plugins and their worker.
     *
     * The counts this returns are the run's own, taken from the same emission the comparison uses. A survey
     * count quoted here would be a different number for a different target.
     *
     * @return array{emitted: int, refused: int}
     */
    public function emit(): array
    {
        $this->reset();

        $target = Transpiler::$target;
        $survey = Transpiler::$survey;
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        try {
            foreach ($this->packages as $package) {
                $source = $this->consumerRoot . '/vendor/' . $package . '/src';
                if (! is_dir($source)) {
                    // Skipped, not fatal. Aborting on the first absent package made every consumer that
                    // installs only some of them unusable, which is most of them — and the corpus a rule most
                    // needs is usually the one that installs the package that rule came from. Recorded so the
                    // report can say which packages a run did *not* cover; a count belongs to its
                    // configuration, and "0 findings" from a package that was never read is not a measurement.
                    $this->skipped[] = $package;

                    continue;
                }

                foreach (RulePaths::expand([$source]) as $file) {
                    $this->transpileInto($file);
                }
            }

            if ($this->emitted === []) {
                throw new RuntimeException(
                    'The consumer has none of the configured rule packages installed, so there is nothing to '
                    . 'transpile: ' . implode(', ', $this->packages),
                );
            }
        } finally {
            Transpiler::$target = $target;
            Transpiler::$survey = $survey;
        }

        $this->writeWorker();

        return ['emitted' => count($this->emitted), 'refused' => count($this->refused)];
    }

    /** @return list<array{name: string, class: string, identifiers: list<string>, register: bool}> */
    public function emittedRules(): array
    {
        return $this->emitted;
    }

    /** @return list<array{name: string, reason: string}> */
    public function refusedRules(): array
    {
        return $this->refused;
    }

    /**
     * The identifiers under which the emitted rules report, and which rules report under each.
     *
     * @return array<string, list<string>>
     */
    public function identifiers(): array
    {
        $identifiers = [];
        foreach ($this->emitted as $rule) {
            // Every identifier the rule takes, not only the last: a merged rule reports under one per check,
            // and comparing on one of them measures a single check while the rest pass by being ignored.
            foreach ($rule['identifiers'] as $identifier) {
                $identifiers[$this->identifierPrefix($identifier)][] = $rule['name'];
            }
        }

        foreach ($identifiers as $identifier => $rules) {
            $identifiers[$identifier] = array_values(array_unique($rules));
        }

        ksort($identifiers);

        return $identifiers;
    }

    /**
     * The `.php` files under the analysed paths, which is what "the corpus" means for both tools.
     *
     * Counted from the filesystem rather than from either tool's own report: a tool that skipped a directory
     * would otherwise get to define the corpus it was measured on.
     *
     * @return list<string>
     */
    public function corpusFiles(): array
    {
        $files = [];
        foreach ($this->paths as $path) {
            $absolute = $this->absolute($path);
            if (is_file($absolute)) {
                if (! $this->isExcluded($absolute)) {
                    $files[] = $absolute;
                }

                continue;
            }

            foreach ($this->phpFilesIn($absolute) as $file) {
                if (! $this->isExcluded($file)) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The plugins' findings as `identifier => list of "file:line: message"`.
     *
     * @return array<string, list<string>>
     */
    public function magoFindings(?int $threads = null): array
    {
        $command = [$this->repositoryRoot . '/vendor/bin/mago'];
        if ($threads !== null) {
            $command[] = '--threads';
            $command[] = (string) $threads;
        }

        $output = $this->run([...$command, 'analyze', '--reporting-format', 'json'], $this->sandbox);

        /** @var array{issues?: list<array{code?: string, message?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}, start?: array{line?: int}}}>}>}|null $decoded */
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("mago produced no JSON:\n" . substr($output, 0, 4000));
        }

        $findings = [];
        foreach ($decoded['issues'] ?? [] as $issue) {
            // Mago spells a transpiled rule's code `transpiled/<kebab-plugin>/<identifier>`, and reports its
            // own native diagnostics on the same run. Only the identifiers under test count.
            $identifier = $this->identifierIn((string) ($issue['code'] ?? ''));
            if ($identifier === null) {
                continue;
            }

            $span = $issue['annotations'][0]['span'] ?? [];
            // Mago's JSON line is 0-based where PHPStan's is 1-based, and the two are compared.
            $line = ((int) ($span['start']['line'] ?? 0)) + 1;
            $findings[$identifier][] = $this->relative((string) ($span['file_id']['name'] ?? ''))
                . ':' . $line . ': ' . ($issue['message'] ?? '');
        }

        return $this->sorted($findings);
    }

    /**
     * The original rules' findings as `identifier => list of "file:line: message"`.
     *
     * @return array<string, list<string>>
     */
    public function phpstanFindings(): array
    {
        $output = $this->run([
            $this->consumerRoot . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            // `laravel/pao` wraps PHPStan's output and caps it at 30 file-errors, setting `truncated: true`.
            // It is a dev dependency of many projects here, so the cap is the normal case rather than the
            // odd one, and a capped original is unusable: `PhpstanReport` refuses it. The wrapper skips the
            // cap when the run is verbose, which is the whole of the fix — measured in
            // `vendor/laravel/pao/src/Drivers/Phpstan/Starter.php`, where `isVerbose()` looks for exactly
            // this flag and `$limit = 30` is applied only when it is absent.
            '-v',
            '--configuration=' . $this->sandbox . '/phpstan-differential.neon',
        ], $this->consumerRoot);

        $findings = [];
        foreach (array_keys($this->identifiers()) as $identifier) {
            foreach (PhpstanReport::findings($output, $identifier, $identifier, $this->consumerRoot) as $file => $lines) {
                foreach ($lines as $line) {
                    $findings[$identifier][] = $file . ':' . $line;
                }
            }
        }

        return $this->sorted($findings);
    }

    /**
     * Writes the PHPStan configuration the originals run under: the consumer's own, plus these rules.
     *
     * The consumer's config is included by absolute path so its own relative paths still resolve, and its
     * baseline is replaced with nothing — neon's `!` overwrites where a plain key would merge.
     *
     * **The consumer's parameter values are left alone, and {@see FiresGate} does the opposite on purpose.**
     * That gate registers the original with the package's own defaults, because the plugin carries those and
     * "a rule whose two sides are configured differently is not a comparison" — it is proving a *translation*.
     * This is proving something else: what a consumer running the port would see instead of what their PHPStan
     * tells them today. Those differ, and the difference is most of what this instrument reports.
     *
     * Measured, so the cost of the choice is on the record rather than implied. Across `nikic/php-parser` and
     * `league/commonmark`, 57 of 59 disagreements are exactly this — the consumer sets
     * `cognitive_complexity: class: 80, function: 20` and `tomasvotruba/cognitive-complexity` ships `40` and
     * `9`, so the port's threshold is lower and it reports more.
     *
     * Forcing both sides to package defaults here would take that number to 2 and measure a different question
     * — one the fires-gate already answers, per rule, against a reviewed example pair. A consumer deciding
     * whether to switch needs this one. The manifest names every parameter now, so closing the 57 is a value
     * the consumer passes rather than a change to either instrument.
     */
    public function writePhpstanConfig(): string
    {
        $services = [];
        foreach ($this->emitted as $rule) {
            // A rule taking a configured value cannot be registered bare — PHPStan refuses to construct it —
            // so it runs under the registration its own package ships, which the extension installer includes.
            // That registration is not assumed to have happened: an identifier that reports on neither side is
            // reported as unproven rather than counted as agreement.
            if (! $rule['register']) {
                continue;
            }

            $services[] = '    -';
            $services[] = '        class: ' . $rule['class'];
            $services[] = '        tags: [phpstan.rules.rule]';
        }

        $paths = [];
        foreach ($this->paths as $path) {
            $paths[] = '        - ' . $this->absolute($path);
        }

        $config = implode("\n", [
            'includes:',
            '    - ' . self::configurationOf($this->consumerRoot),
            '',
            'parameters:',
            '    ignoreErrors!: []',
            '    reportUnmatchedIgnoredErrors: false',
            // Forced past whatever the consumer configures. `--error-format=json` on the command line did not
            // win on one project, whose run came back in a wrapping envelope that caps how many errors it
            // lists — 30 of 1160 — and the cap reads as a clean original. `!` overwrites the included value
            // rather than merging with it, the same way the two keys above do.
            '    errorFormat: json',
            // The same forced values the plugins are constructed with, so an override changes both sides at
            // once. Changing one alone would measure the port against a configuration the original is not
            // running, which is the mistake the whole override exists to correct for.
            ...array_map(
                // No `!` on these, unlike the keys above: neon's replacing operator applies to arrays only,
                // and on a scalar it fails the run with "Replacing operator is available only for arrays".
                // A plain scalar assignment in the including file already wins over the included one.
                static fn (string $name, bool $value): string => '    ' . $name . ': ' . ($value ? 'true' : 'false'),
                array_keys($this->overrides),
                array_values($this->overrides),
            ),
            '    paths!:',
            ...$paths,
            '',
            'services:',
            ...$services,
            '',
        ]);

        file_put_contents($this->sandbox . '/phpstan-differential.neon', $config);

        return $this->sandbox . '/phpstan-differential.neon';
    }

    /**
     * Writes the mago configuration: the same paths, the consumer's vendor tree, and the combined worker.
     *
     * `includes` is the resolution context — scanned for symbols, never analysed or reported — and it is what
     * makes the comparison fair. PHPStan has the consumer's autoloader, so a rule asking whether a class is an
     * `Exception` gets an answer even when the parent is a framework class. Without the vendor tree here, mago
     * cannot walk that chain and the port reports nothing for those classes: 13 of 31 exception findings, all
     * of them classes extending a framework exception. That is engine blindness, not a narrow port, and the
     * fix belongs in the configuration rather than in the agreement math.
     */
    public function writeMagoConfig(): string
    {
        $paths = [];
        foreach ($this->paths as $path) {
            $paths[] = '"' . $this->absolute($path) . '"';
        }

        // The consumer's own exclusions, or the corpora are not the same one. `app/Macros` is excluded from
        // this consumer's analysis, and without this the port reported two findings in a file the original
        // never opened — a corpus mismatch that reads exactly like the port being too wide.
        $excludes = [];
        foreach ($this->excludes as $exclude) {
            $excludes[] = '"' . $this->absolute($exclude) . '"';
            $excludes[] = '"' . $this->absolute($exclude) . '/**"';
        }

        file_put_contents($this->sandbox . '/mago.toml', <<<TOML
            [source]
            paths = [{$this->join($paths)}]
            includes = ["{$this->consumerRoot}/vendor"]
            excludes = [{$this->join($excludes)}]

            [extension-hosts.differential]
            command = ["php", "worker.php"]

            TOML);

        return $this->sandbox . '/mago.toml';
    }

    /**
     * The comparison, per identifier, as `(file, line)` sites plus the messages behind them.
     *
     * @param array<string, list<string>> $original
     * @param array<string, list<string>> $port
     *
     * @return array<string, array{agree: list<string>, onlyOriginal: list<string>, onlyPort: list<string>, suppressed: list<string>, differingMessages: list<string>}>
     */
    public function compare(array $original, array $port): array
    {
        $comparison = [];
        foreach (array_keys($this->identifiers()) as $identifier) {
            $left = $this->bySite($original[$identifier] ?? []);
            $right = $this->bySite($port[$identifier] ?? []);

            $agree = [];
            $differing = [];
            foreach ($left as $site => $message) {
                if (! isset($right[$site])) {
                    continue;
                }

                $agree[] = $site;
                if ($right[$site] !== $message) {
                    $differing[] = $site . "\n      original: " . $message . "\n      port:     " . $right[$site];
                }
            }

            [$onlyPort, $suppressed] = (new Suppressions($this->consumerRoot))->split(
                array_values(array_diff(array_keys($right), array_keys($left))),
                $identifier,
            );

            $comparison[$identifier] = [
                'agree' => $agree,
                'onlyOriginal' => array_values(array_diff(array_keys($left), array_keys($right))),
                'onlyPort' => $onlyPort,
                'suppressed' => $suppressed,
                'differingMessages' => $differing,
            ];
        }

        return $comparison;
    }

    private function reset(): void
    {
        $this->emitted = [];
        $this->refused = [];
        $this->skipped = [];
        $plugins = $this->sandbox . '/plugins';
        if (is_dir($plugins)) {
            foreach ($this->phpFilesIn($plugins) as $stale) {
                unlink($stale);
            }
        } else {
            mkdir($plugins, 0o777, true);
        }
    }

    private function transpileInto(string $file): void
    {
        $name = basename($file, '.php');

        try {
            $rule = (new Transpiler($file))->transpile();
        } catch (Throwable $throwable) {
            $this->refused[] = ['name' => $name, 'reason' => $throwable->getMessage()];

            return;
        }

        file_put_contents($this->sandbox . '/plugins/' . $name . '.php', $rule['rust'] . "\n");
        $this->emitted[] = [
            'name' => $name,
            'class' => $this->classOf($file, $name),
            'identifiers' => $rule['identifiers'],
            'register' => $this->isAutowireable($file),
        ];
    }

    /** Why the consumer's parameter values could not be read, so a run says so instead of looking normal. */
    public ?string $parameterFailure = null;

    private function writeWorker(): void
    {
        $consumer = new ConsumerParameters($this->consumerRoot, $this->sandbox, fn (array $command): string => $this->run($command, $this->consumerRoot), $this->overrides);
        $plugins = [];
        foreach ($this->emitted as $rule) {
            $plugins[] = 'new \\Transpiled\\' . $rule['name'] . '(' . $consumer->argumentsFor($rule['name']) . ')';
        }

        $this->parameterFailure = $consumer->failure;

        file_put_contents($this->sandbox . '/worker.php', strtr(self::WORKER, [
            '{autoload}' => $this->repositoryRoot . '/vendor/autoload.php',
            '{plugins}' => $this->join($plugins),
        ]));
    }

    /**
     * Whether PHPStan can construct this rule with no arguments, which decides whether to register it here.
     *
     * A parameter with no default whose type is not a class cannot be autowired: `array $firstPartyNamespaces`
     * needs a configured value, and registering such a rule bare makes PHPStan abort before analysing. Read
     * from the constructor rather than from a list of known rules, so a package adding one is handled.
     */
    private function isAutowireable(string $file): bool
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $statements = $parser->parse((string) file_get_contents($file)) ?? [];
        $constructor = (new NodeFinder())->findFirst(
            $statements,
            static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === '__construct',
        );

        if (! $constructor instanceof ClassMethod) {
            return true;
        }

        foreach ($constructor->params as $parameter) {
            if ($parameter->default !== null) {
                continue;
            }

            $type = $parameter->type;
            $name = $type instanceof NullableType ? $type->type : $type;
            if (! $name instanceof Name) {
                return false;
            }
        }

        return true;
    }

    /** The rule's own fully qualified name, read from its file rather than guessed from its path. */
    private function classOf(string $file, string $name): string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $matches) !== 1) {
            throw new RuntimeException("No namespace in {$file}, so the original rule cannot be registered");
        }

        return trim($matches[1]) . '\\' . $name;
    }

    /**
     * The identifier under test that this mago code belongs to, or null when the code is not one of them.
     */
    private function identifierIn(string $code): ?string
    {
        foreach (array_keys($this->identifiers()) as $identifier) {
            if (str_contains($code, '/' . $identifier)) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * The literal part of a rule's identifier, which is what both sides can be filtered on.
     *
     * A rule that classifies what it found reports under a computed code — `'hihaho.debug.noDebugIn' .
     * $namespace` — so the leading literal is what every code it can report has in common.
     */
    private function identifierPrefix(string $identifier): string
    {
        if (! str_contains($identifier, "'")) {
            return $identifier;
        }

        $quoted = explode("'", $identifier);

        return $quoted[1] ?? $identifier;
    }

    /**
     * @param list<string> $findings each `file:line: message`
     *
     * @return array<string, string>
     */
    private function bySite(array $findings): array
    {
        $bySite = [];
        foreach ($findings as $finding) {
            $parts = explode(': ', $finding, 2);
            $bySite[$parts[0]] = $parts[1] ?? '';
        }

        return $bySite;
    }

    /** Whether the consumer's own configuration keeps this file out of its analysis. */
    private function isExcluded(string $path): bool
    {
        foreach ($this->excludes as $exclude) {
            $absolute = $this->absolute($exclude);
            if ($path === $absolute || str_starts_with($path, $absolute . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A path as given, which may already be absolute — a control run points both tools outside the consumer. */
    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->consumerRoot . '/' . $path;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->consumerRoot . '/')
            ? substr($path, strlen($this->consumerRoot) + 1)
            : $path;
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /** @param list<string> $parts */
    private function join(array $parts): string
    {
        return implode(', ', $parts);
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $directory): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, Subprocess::environment());
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Both tools exit non-zero whenever they find something, which is the expected outcome here. A real
        // failure shows up as unparseable output instead.
        proc_close($process);

        return $stdout === '' ? $stderr : $stdout;
    }

    /**
     * @param array<string, list<string>> $findings
     *
     * @return array<string, list<string>>
     */
    private function sorted(array $findings): array
    {
        foreach ($findings as $identifier => $lines) {
            sort($lines);
            $findings[$identifier] = $lines;
        }

        ksort($findings);

        return $findings;
    }
}
