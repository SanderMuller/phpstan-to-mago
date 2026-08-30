<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sandermuller\PhpstanToMago\RegisteredRules;
use Sandermuller\PhpstanToMago\Transpiler;
use SplFileInfo;

/**
 * Runs one emitted plugin under the real mago binary, and the rule it came from under PHPStan.
 *
 * The other gates prove an emitted plugin parses, contains no Rust and calls only helpers that exist.
 * None of that proves it *ran*: a version of statement-position inlining passed every static check,
 * loaded, and silently found nothing because an unconditional exit sat in front of the report. Only
 * running caught it, which is why this harness shells out rather than asserting on text.
 *
 * A finding is compared as `line: message`, not just a line. The message is user-facing, and a port whose
 * message differs is wrong in the way a reader notices first — `Doctrine\Bundle\...` arriving as
 * `DoctrineBundle...` lands on the right line and still tells the reader the wrong class.
 *
 * The worker registers **one** plugin. That is load-bearing rather than tidy: a node hook's ancestors
 * turned out to depend on what else shares the worker, so a rule that fires in a crowded extension can
 * be dead on its own. A one-rule worker is the honest configuration.
 */
final readonly class FiresGate
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
        require __DIR__ . '/plugin.php';

        (new Worker(new Extension(
            identifier: 'gate/one-rule',
            name: 'One transpiled rule',
            version: '0.0.0',
            analyzerPlugins: [new \Transpiled\{rule}({pluginArguments})],
        )))->run();
        PHP;

    private const string MAGO_CONFIG = <<<'TOML'
        [source]
        paths = ["src", "stubs"]
        # The resolution context PHPStan gets from its autoloader, so an example may name a vendored class and
        # both tools answer about the same hierarchy. Without it mago cannot walk into a vendored parent and a
        # rule asking about one goes silently narrow — the asymmetry the corpus differential found first.
        #
        # Named packages rather than all of `vendor`: this is scanned once per rule, and the whole tree took the
        # suite from 115s to 346s, past the point where `composer qa-check` gives up. Add a package here when an
        # example needs to name one of its classes.
        includes = ["{ROOT}/vendor/nikic", "{ROOT}/vendor/rector/rector/src/Contract", "{ROOT}/vendor/rector/rector/src/Rector", "{ROOT}/vendor/laravel/framework/src/Illuminate/Collections", "{ROOT}/vendor/laravel/framework/src/Illuminate/Database/Eloquent"]

        [extension-hosts.gate]
        command = ["php", "worker.php"]
        TOML;

    /**
     * The conditions the facade-alias rule was written to run under.
     *
     * `DetectsFacadeAlias` resolves a bare short name with `new ReflectionClass($name)`, and its own comment
     * says why: aliases are registered lazily by `AliasLoader`, an SPL autoloader. Under PHPStan that works
     * only because larastan's `bootstrapFiles` boots the application, which runs `RegisterFacades`. Without
     * something equivalent here the original resolves nothing, both tools report nothing, and the pair would
     * pass by agreeing on silence.
     *
     * So the loader is registered directly from `Facade::defaultAliases()` — the same map the port reads, and
     * the narrowest thing that reproduces the original's operating conditions without booting an application
     * in a test. Guarded, so a repository without Laravel installed simply gets no aliases.
     */
    private const string PHPSTAN_BOOTSTRAP = <<<'PHP'
        <?php

        declare(strict_types=1);

        $facade = Illuminate\Support\Facades\Facade::class;
        $loader = Illuminate\Foundation\AliasLoader::class;
        if (class_exists($facade) && class_exists($loader)) {
            $aliases = $facade::defaultAliases();
            $aliases = is_array($aliases) ? $aliases : $aliases->all();

            // An example project may declare its own aliases, the way every Laravel project does, and a
            // booted application would register those too. Evaluated rather than parsed: this stands in for
            // the boot, not for the port — the port reads the same entries out of the CST, and a bootstrap
            // that re-implemented that would prove only that two copies of it agree.
            foreach (glob(__DIR__ . '/src/config-app.php') ?: [] as $config) {
                $declared = require $config;
                if (is_array($declared) && is_array($declared['aliases'] ?? null)) {
                    $aliases = $declared['aliases'];
                }
            }

            $loader::getInstance($aliases)->register();
        }
        PHP;

    private const string PHPSTAN_CONFIG = <<<'NEON'
        parameters:
            # Inside the sandbox, and deleted whenever the sandbox is written. PHPStan's result cache keys on the
            # analysed files and the configuration, and the *rule* is neither — so editing a rule while leaving
            # its examples alone served findings from the previous version of it. That cost a real debugging
            # detour: a fixture I had just corrected still failed with the old fixture's disagreement.
            tmpDir: phpstan-tmp
            level: 0
            bootstrapFiles:
                - bootstrap.php
            reportUnmatchedIgnoredErrors: false
            paths:
                - src
            scanDirectories:
                - stubs
        {parameters}services:
            -
                class: {class}
        {arguments}        tags: [phpstan.rules.rule]
        NEON;

    /**
     * Configured values a rule needs before it can report at all, per rule.
     *
     * A package may ship a parameter empty and expect each project to fill it: `traitRequiresInterface` has no
     * default pairs, so a plugin carrying the package default reports nothing. Both tools would then be silent,
     * and two tools agreeing on nothing is the one result this gate must never accept.
     *
     * So the values are supplied here, to *both* sides, and the pair proves the rule fires when configured. The
     * emitted plugin still carries the package default — a consumer overrides it in its own worker, which is
     * what the constructor parameters are for.
     *
     * @var array<string, array<string, mixed>>
     */
    /**
     * Rules whose configuration comes from a project rather than from the package that ships them.
     *
     * The package registers these nowhere, so there is no neon to read their wiring from and the transpiler
     * refuses them outright unless `--from-config` points it at a project. Named here so the gate emits them
     * the way that flag does, against the container of the project below.
     *
     * @var array<string, string>
     */
    private const array FROM_PROJECT = [
        'ConfiguredByTheProjectRule' => __DIR__ . '/../Fixtures/RegisteredProject',
    ];

    private const array CONFIGURED = [
        // PHPStan's side only, for a rule in {@see FROM_PROJECT}. The plugin is deliberately given nothing:
        // its constructor defaults are what the project's container supplied, and whether those are right is
        // the whole question. Passing them again would test the gate's own table instead.
        'ConfiguredByTheProjectRule' => [
            'banned' => ['dump', 'dd'],
            'alsoBanned' => ['VarDump', 'Ray'],
        ],
        // Two pairs, not one. The rule accumulates a finding per violated pair and reports each at the
        // class's own line, so a class using both traits and implementing neither is two findings at one
        // span — the only shape that separates "per violated pair" from "once per class".
        'TraitRequiresInterfaceRule' => [
            'traitRequiresInterface' => [
                'Examples\\Contracts\\Localised' => 'Examples\\Contracts\\LocalisedContract',
                'Examples\\Contracts\\Auditable' => 'Examples\\Contracts\\AuditableContract',
            ],
        ],
    ];

    /**
     * PHPStan service arguments a rule needs, per rule, for the PHPStan side only.
     *
     * Separate from {@see CONFIGURED} because a service is not a configured value: it goes to PHPStan as a
     * container reference and has no counterpart on the plugin, whose whole point is that it asks Mago the
     * same question without the service. `CombinedMethodCallRule` takes `PHPStan\Parser\Parser` so it can
     * parse the file another class is declared in; without it PHPStan cannot construct the rule at all, and
     * the pair would look like a rule that reports nothing.
     *
     * @var array<string, array<string, string>>
     */
    /**
     * Neon *parameters* a rule needs, per rule, for the PHPStan side.
     *
     * Distinct from {@see CONFIGURED}, which passes constructor arguments. A rule taking a package value object
     * has no arguments to pass: it reads a `Configuration` service built from the package's own parameter
     * tree, so the only way to change what it sees is to change the parameter. The threshold rules need that —
     * `cognitive_complexity.class` defaults to 40, and no fixture worth reading trips 40.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array PARAMETERS = [
        // `checkThisOnly` defaults true and turns off at level 2, so at this gate's level 0 the original
        // reports nothing for any subject that is not `$this` -- the whole boolean-condition family. The
        // emitted plugin carries the same flag as a constructor parameter at the same default, so both sides
        // are set here rather than one of them being left to a default that differs.
        //
        'BooleanInIfConditionRule' => ['checkThisOnly' => false],
        'BooleanInElseIfConditionRule' => ['checkThisOnly' => false],
        'BooleanInBooleanNotRule' => ['checkThisOnly' => false],
        'BooleanInWhileConditionRule' => ['checkThisOnly' => false],
        'BooleanInDoWhileConditionRule' => ['checkThisOnly' => false],
        'BooleanInTernaryOperatorRule' => ['checkThisOnly' => false],
        'ClassLikeCognitiveComplexityRule' => [
            'cognitive_complexity' => ['class' => 3],
        ],
        'FunctionLikeCognitiveComplexityRule' => [
            'cognitive_complexity' => ['function' => 2],
        ],
    ];

    /**
     * Neon parameters that decide whether PHPStan *registers* a rule, per rule.
     *
     * Kept apart from {@see PARAMETERS} because those reach the plugin too -- a value configured on one side
     * only is not a comparison, so that map deliberately feeds both. These reach neither: they are the
     * package's own registration switches, and the plugin has no property to put them in. Passing them
     * through the same path handed the generated constructor an argument it does not declare.
     *
     * `booleansInLoopConditions` is tagged `[%strictRules.allRules%, %featureToggles.bleedingEdge%]`, so the
     * two loop rules are not registered at all without bleeding edge, unlike their five siblings. Named per
     * rule rather than toggling bleeding edge globally, which would quietly change every other rule here.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array REGISTRATION = [
        'BooleanInWhileConditionRule' => ['strictRules' => ['booleansInLoopConditions' => true]],
        'BooleanInDoWhileConditionRule' => ['strictRules' => ['booleansInLoopConditions' => true]],
    ];

    private const array SERVICES = [
        'CombinedMethodCallRule' => [
            'parser' => '@defaultAnalysisParser',
        ],
    ];

    public function __construct(
        private string $repositoryRoot,
        private string $examplesRoot,
        private string $sandboxRoot,
    ) {}

    /**
     * Whether an example pair exists for this rule.
     *
     * Asked separately from running it so the gate can fail an emitted rule that has no pair, rather
     * than passing it by never looking. An emitted rule nobody wrote an example for is untested, and
     * silence there is what let five dead rules ship.
     */
    public function hasExamples(string $rule): bool
    {
        return $this->examples($rule, 'Bad') !== [] && $this->examples($rule, 'Good') !== [];
    }

    /**
     * The example files of one kind, by base name.
     *
     * A pair is identified by the `Bad`/`Good` prefix rather than by a fixed `Bad.php`, because some rules
     * key on the file name: `TestClassDetector::isTestClass()` asks whether the path ends in `Test.php`,
     * `TestCase.php` or `Context.php`. Such a rule needs its example called `BadSomethingTest.php`, and a
     * gate that renamed it would test the wrong thing.
     *
     * @return list<string>
     */
    public function examples(string $rule, string $kind): array
    {
        $paths = glob($this->examplesRoot . '/' . $rule . '/{,*/,*/*/,*/*/*/,*/*/*/*/}' . $kind . '*.php', GLOB_BRACE);

        return array_map(basename(...), $paths === false ? [] : $paths);
    }

    /**
     * The lines the transpiled plugin reports, keyed by the file they land in.
     *
     * @return array<string, list<string>>
     */
    public function magoFindings(string $rule, string $ruleFile): array
    {
        return GateFindings::remember(
            'mago|' . $this->fingerprint($rule, $ruleFile),
            fn (): array => $this->runMago($rule, $ruleFile),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function runMago(string $rule, string $ruleFile): array
    {
        $prefixes = $this->identifierPrefixesOf($ruleFile);
        $sandbox = $this->sandbox($rule, $ruleFile);
        $output = $this->run(['./mago', 'analyze', '--reporting-format', 'json'], $sandbox);

        /** @var array{issues?: list<array{code?: string, message?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}, start?: array{line?: int}}}>}>}|null $decoded */
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("mago produced no JSON for {$rule}:\n" . $output);
        }

        $findings = [];
        foreach ($decoded['issues'] ?? [] as $issue) {
            // Only this rule's own findings count, on both sides. The engine reports unresolvable classes
            // and other native diagnostics on the same run, and PHPStan reports its own level-0 errors;
            // counting either would compare two different things. Mago spells the code
            // `transpiled/<kebab-plugin>/<rule identifier>`, PHPStan spells it `<rule identifier>`.
            $code = (string) ($issue['code'] ?? '');
            $mine = false;
            foreach ($prefixes as $prefix) {
                if (str_contains($code, '/' . $prefix)) {
                    $mine = true;

                    break;
                }
            }

            if (! $mine) {
                continue;
            }

            // The path lives at `span.file_id.name`, not `span.file`.
            $annotation = $issue['annotations'][0] ?? [];
            $file = basename((string) ($annotation['span']['file_id']['name'] ?? ''));
            // Mago's JSON line is 0-based; PHPStan's is 1-based, and the two are compared.
            $line = ((int) ($annotation['span']['start']['line'] ?? 0)) + 1;
            $findings[$file][] = $line . ': ' . ($issue['message'] ?? '');
        }

        return $this->sorted($findings);
    }

    /**
     * The lines PHPStan reports for the original rule, keyed by the file they land in.
     *
     * @return array<string, list<string>>
     */
    public function phpstanFindings(string $rule, string $ruleFile, string $ruleClass): array
    {
        return GateFindings::remember(
            'phpstan|' . $ruleClass . '|' . $this->fingerprint($rule, $ruleFile),
            fn (): array => $this->runPhpstan($rule, $ruleFile, $ruleClass),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function runPhpstan(string $rule, string $ruleFile, string $ruleClass): array
    {
        $sandbox = $this->sandbox($rule, $ruleFile, $ruleClass);
        $output = $this->run([
            $this->repositoryRoot . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=phpstan.neon',
            '--autoload-file=' . $this->repositoryRoot . '/vendor/autoload.php',
        ], $sandbox);

        return PhpstanReport::findings($output, $this->identifierPrefixesOf($ruleFile), $rule);
    }

    /**
     * A throwaway project holding one emitted plugin, its worker, and the example pair.
     *
     * Rebuilt per call rather than cached: mago has no result cache, so a stale sandbox would be the
     * one thing that could make a dead rule look alive.
     */
    /**
     * What a rule's findings depend on, so a run shares them and an edit does not.
     *
     * The rule's own source, every example beside it, and the target. {@see GateFindings} says why.
     */
    private function fingerprint(string $rule, string $ruleFile): string
    {
        $stamps = [Transpiler::$target, (string) filemtime($ruleFile)];
        $examples = glob($this->examplesRoot . '/' . $rule . '/{,*/,*/*/,*/*/*/,*/*/*/*/}*.php', GLOB_BRACE);
        foreach ($examples === false ? [] : $examples as $example) {
            $stamps[] = $example . ':' . filemtime($example);
        }

        return hash('sha256', implode('|', $stamps));
    }

    private function sandbox(string $rule, string $ruleFile, string $ruleClass = 'Stub'): string
    {
        $sandbox = $this->sandboxRoot . '/' . $rule;

        // Emptied, not topped up. Copying examples into whatever was there before leaves a renamed or deleted
        // one behind, and a stale example is worse than a missing one: it produces a disagreement about a file
        // that no longer exists. Renaming `GoodFlagShapes` to `Bad…` left both copies in the sandbox and the
        // pair failed naming the old one, which reads exactly like a port defect.
        $this->removeDirectory($sandbox . '/src');
        mkdir($sandbox . '/src', 0o777, true);

        // The rule may have changed since the last run, and PHPStan's result cache cannot see that.
        $this->removeDirectory($sandbox . '/phpstan-tmp');

        $plugin = $this->transpile($ruleFile);
        file_put_contents($sandbox . '/plugin.php', $plugin . "\n");
        file_put_contents($sandbox . '/worker.php', strtr(self::WORKER, [
            '{autoload}' => $this->repositoryRoot . '/vendor/autoload.php',
            '{rule}' => $rule,
            '{pluginArguments}' => $this->pluginArguments($ruleFile),
        ]));
        file_put_contents($sandbox . '/mago.toml', strtr(self::MAGO_CONFIG, ['{ROOT}' => $this->repositoryRoot]) . "\n");
        file_put_contents($sandbox . '/bootstrap.php', self::PHPSTAN_BOOTSTRAP . "\n");
        file_put_contents($sandbox . '/phpstan.neon', strtr(self::PHPSTAN_CONFIG, [
            '{class}' => $ruleClass,
            '{arguments}' => $this->arguments($ruleFile),
            '{parameters}' => $this->parameters($ruleFile),
        ]) . "\n");

        // Copied keeping any directories the example sits in, because a rule may ask about its own path:
        // `NoBundleResourceConfigRule` reports only for a file under `Resources/config`, so a flat sandbox could
        // never make it fire and the pair would look like a dead rule. Findings are still compared by base name,
        // so the names have to stay unique within a rule's examples.
        $root = $this->examplesRoot . '/' . $rule;
        $examples = glob($root . '/{,*/,*/*/,*/*/*/,*/*/*/*/}*.php', GLOB_BRACE);
        foreach ($examples === false ? [] : $examples as $example) {
            $target = $sandbox . '/src/' . ltrim(substr($example, strlen($root)), '/');
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }

            copy($example, $target);
        }

        // The stubs sit beside `src`, not inside it: mago needs them in its source paths to resolve
        // ancestry, while PHPStan must scan them without analysing them, and one directory cannot be both
        // analysed and excluded.
        if (! is_dir($sandbox . '/stubs')) {
            mkdir($sandbox . '/stubs', 0o777, true);
        }

        $stubs = glob($this->examplesRoot . '/stubs/*.php');
        foreach ($stubs === false ? [] : $stubs as $stub) {
            copy($stub, $sandbox . '/stubs/' . basename($stub));
        }

        // A relative `command` resolves from the config file's directory, and the binary is symlinked
        // in so the sandbox needs no absolute path baked into a committed file.
        if (! is_file($sandbox . '/mago')) {
            symlink($this->repositoryRoot . '/vendor/bin/mago', $sandbox . '/mago');
        }

        return $sandbox;
    }

    /**
     * The neon parameters this rule needs, rendered under the existing `parameters:` key.
     *
     * @return string neon lines, or an empty string when the rule needs none
     */
    private function parameters(string $ruleFile): string
    {
        $parameters = (self::PARAMETERS[basename($ruleFile, '.php')] ?? [])
            + (self::REGISTRATION[basename($ruleFile, '.php')] ?? []);
        if ($parameters === []) {
            return '';
        }

        $lines = [];
        foreach ($parameters as $root => $values) {
            // A scalar is a top-level parameter rather than a tree. `checkThisOnly` is one, and it has to be
            // settable: it defaults *true* and turns off at level 2, so at the level 0 this gate runs, PHPStan
            // silences every rule reading `RuleLevelHelper` for a subject that is not `$this`. Both sides
            // would then report nothing, which is the agreement-on-zero this gate exists to refuse.
            if (! is_array($values)) {
                $lines[] = '    ' . $root . ': ' . json_encode($values, JSON_THROW_ON_ERROR);

                continue;
            }

            $lines[] = '    ' . $root . ':';
            foreach ($values as $key => $value) {
                $lines[] = '        ' . $key . ': ' . json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The configured values to register the original rule with, as neon.
     *
     * Taken from the transpiler, which read them from the rule package's own neon and put them in the
     * generated plugin as constructor defaults. Both sides then run at the package's defaults — a rule whose
     * two sides are configured differently is not a comparison, and PHPStan refuses to construct a rule whose
     * scalar parameter nobody supplied, which is why these rules were outside the gate until now.
     *
     * {@see CorpusDifferential} deliberately does *not* do this, and its docblock says why: it measures what a
     * consumer would see at their own configuration rather than whether the translation is faithful. Two
     * instruments, two questions. Neither should be changed to match the other.
     */
    private function arguments(string $ruleFile): string
    {
        // A rule configured through a *parameter* takes no configured arguments: its constructor asks for the
        // package's value object, and handing it the plugin's constructor arguments instead is
        // "Unable to pass specified arguments to __construct()". The two mechanisms are alternatives, so
        // naming a parameter override suppresses the argument list here — the plugin still gets it, because
        // that is the shape the transpiler gave it.
        $arguments = isset(self::PARAMETERS[basename($ruleFile, '.php')])
            || isset(self::REGISTRATION[basename($ruleFile, '.php')])
            ? []
            : $this->configuredValues($ruleFile);
        $services = self::SERVICES[basename($ruleFile, '.php')] ?? [];
        if ($arguments === [] && $services === []) {
            return '';
        }

        $lines = ['        arguments:'];
        foreach ($services as $name => $reference) {
            // Written raw: neon reads `@name` as a service reference, and quoting it hands the rule a string.
            $lines[] = '            ' . $name . ': ' . $reference;
        }

        foreach ($arguments as $name => $value) {
            if (! is_array($value)) {
                $lines[] = '            ' . $name . ': ' . json_encode($value, JSON_THROW_ON_ERROR);

                continue;
            }

            $lines[] = '            ' . $name . ':';
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                // A map carries its keys — `traitRequiresInterface` is trait => interface, and a list of the
                // values alone would leave nothing to check them against.
                $lines[] = is_string($key)
                    ? '                ' . json_encode($key, JSON_THROW_ON_ERROR) . ': ' . json_encode($item, JSON_THROW_ON_ERROR)
                    : '                - ' . json_encode($item, JSON_THROW_ON_ERROR);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The configured values both sides register the rule with: the package's, plus any this gate supplies.
     *
     * @return array<string, mixed>
     */
    private function configuredValues(string $ruleFile): array
    {
        // For a rule in {@see FROM_PROJECT} the transpiled arguments are the *plugin's* properties, and a
        // derived one has no constructor parameter to match it: `bannedLookup` is computed from
        // `alsoBanned`, so handing PHPStan both is "Unable to pass specified arguments to __construct()".
        // The table names what the rule declares; the plugin gets nothing and stands on its defaults.
        if (isset(self::FROM_PROJECT[basename($ruleFile, '.php')])) {
            // Read without a fallback on purpose. A rule emitted against a project must appear in
            // {@see CONFIGURED} too, or PHPStan registers it unconfigured and the pair compares a rule that
            // was given values against one that was not. Adding a `?? []` would make that a silent pass;
            // this way the two tables are checked against each other statically.
            return self::CONFIGURED[basename($ruleFile, '.php')];
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->transpiled($ruleFile)['arguments'];

        return array_merge($arguments, self::CONFIGURED[basename($ruleFile, '.php')] ?? []);
    }

    /**
     * The values the worker constructs the plugin with, as PHP named arguments.
     *
     * Empty for every rule the package configures itself, which is what keeps those workers unchanged. Where
     * this gate supplies a value the plugin has to receive the same one, or the two sides are registered
     * differently and the comparison means nothing.
     */
    private function pluginArguments(string $ruleFile): string
    {
        // A neon parameter override reaches the plugin as a *constructor argument*, because that is the shape
        // the transpiler gives it: the parameter's last segment is the property name. One override, both sides
        // — the alternative was a second map to keep in step, and a threshold configured on one side only is
        // exactly how this pair first failed, with the plugin still carrying the package default of 40.
        // A rule configured by a project carries those values as its constructor defaults already, and
        // handing them over again would prove the gate can pass an argument rather than that the defaults
        // are the project's. It also cannot work: PHPStan takes the parameter a rule declares and the plugin
        // takes the property, and a derived property has no parameter of its own.
        if (isset(self::FROM_PROJECT[basename($ruleFile, '.php')])) {
            return '';
        }

        $supplied = self::CONFIGURED[basename($ruleFile, '.php')] ?? [];
        foreach (self::PARAMETERS[basename($ruleFile, '.php')] ?? [] as $root => $values) {
            // A top-level scalar names the property itself; a tree names it in its last segment.
            if (! is_array($values)) {
                $supplied[$root] = $values;

                continue;
            }

            foreach ($values as $key => $value) {
                $supplied[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))))] = $value;
            }
        }

        if ($supplied === []) {
            return '';
        }

        $parts = [];
        foreach ($supplied as $name => $value) {
            $parts[] = $name . ': ' . var_export($value, true);
        }

        return implode(', ', $parts);
    }

    /**
     * The identifier the transpiled rule reports under, taken from the transpiler rather than guessed.
     *
     * Both sides of the comparison filter on it: mago spells a finding's code
     * `transpiled/<kebab-plugin>/<identifier>` and PHPStan spells it `<identifier>`.
     */
    /**
     * The literal part of a rule's identifier, which is what both sides can be filtered on.
     *
     * A rule that classifies what it found reports under a computed code — `'fixture.noDebugIn' . $area` —
     * so there is no single literal to match. The leading literal is common to every code the rule can
     * report, and matching on it keeps the comparison over exactly that rule's findings.
     */
    /**
     * Every identifier prefix the rule reports under.
     *
     * A merged rule takes one identifier per check, and comparing on the last one alone measured a single
     * check while the other two passed on being ignored. So the whole set, not `identifier`.
     *
     * @return list<string>
     */
    private function identifierPrefixesOf(string $ruleFile): array
    {
        $identifiers = $this->transpiled($ruleFile)['identifiers'] ?? [];

        $prefixes = [];
        foreach (is_array($identifiers) ? $identifiers : [] as $identifier) {
            if (! is_string($identifier) || $identifier === '') {
                continue;
            }

            // A computed code arrives as the expression that builds it, and the leading literal is what every
            // code it can report has in common: `'hihaho.debug.noDebugIn' . $namespace`.
            $prefixes[] = str_contains($identifier, "'") ? (explode("'", $identifier)[1] ?? $identifier) : $identifier;
        }

        return array_values(array_unique($prefixes));
    }

    private function transpile(string $ruleFile): string
    {
        $rust = $this->transpiled($ruleFile)['rust'];

        return is_string($rust) ? $rust : throw new RuntimeException('the transpiler produced no source');
    }

    /**
     * The transpiler's whole answer for a rule, with the target pinned.
     *
     * @return array<string, mixed>
     */
    private function transpiled(string $ruleFile): array
    {
        $target = Transpiler::$target;
        $survey = Transpiler::$survey;
        $consumer = Transpiler::$consumerConfiguration;
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $project = self::FROM_PROJECT[basename($ruleFile, '.php')] ?? null;
        if ($project !== null) {
            Transpiler::$consumerConfiguration = RegisteredRules::discover(
                $project,
                $this->repositoryRoot . '/vendor/bin/phpstan',
            );
        }

        try {
            return (new Transpiler($ruleFile))->transpile();
        } finally {
            Transpiler::$target = $target;
            Transpiler::$survey = $survey;
            Transpiler::$consumerConfiguration = $consumer;
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $sandbox): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $sandbox, Subprocess::environment());
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // The exit code says nothing: mago exits non-zero whenever it finds an issue, which is the
        // expected outcome here. A real failure shows up as unparseable output instead.
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
        foreach ($findings as $file => $lines) {
            // Deduped because the PHPStan side is: `PhpstanReport` collapses one site reported many times,
            // and a gate where only one side collapses would fail on an example holding two identical
            // same-line violations.
            $lines = array_values(array_unique($lines));
            sort($lines);
            $findings[$file] = $lines;
        }

        ksort($findings);

        return $findings;
    }

    /** Removes a directory and everything under it, for a cache that must not outlive a rule edit. */
    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
