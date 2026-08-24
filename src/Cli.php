<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use Throwable;

/**
 * The command line entry point.
 *
 * Takes rule source paths and writes one file per rule that could be translated, plus a line per
 * rule saying whether it was emitted or refused and why. A refusal is the useful output: it names a
 * construct the vocabulary does not cover, rather than guessing at it.
 */
final class Cli
{
    /**
     * @param list<string> $argv rule source paths or directories, plus any of --target, --survey, --examples,
     *                           --from-config
     *
     * @return int 0 when every rule was emitted
     */
    public static function run(array $argv, string $outRoot): int
    {
        // Both of these refuse for the same kind of reason -- an argument that names something this cannot
        // work with -- and a refusal reads the same either way, so they share the one handler.
        try {
            $options = Options::parse($argv);

            Transpiler::$target = $options->target;
            Transpiler::$survey = $options->survey;
            Transpiler::$allowUnverified = $options->unverified;
            if ($options->examplesDir !== null) {
                Transpiler::$examplesDir = $options->examplesDir;
            }

            $files = self::files($options);
        } catch (Refusal $refusal) {
            echo '  REFUSE  ', $refusal->getMessage(), "\n";

            return 1;
        }

        $outDir = $options->outDir($outRoot);
        self::ensureDirectory($outDir);

        // The count means nothing without the target: a rule can render as Rust and be refused as PHP, so
        // surveying one target and emitting the other silently disagrees. Naming it here is what stops that
        // being read as leniency in the survey.
        echo ($options->survey ? '  SURVEY  ' : '  TARGET  '), $options->target, "\n\n";

        $rules = [];
        $refused = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            try {
                $rule = (new Transpiler($file))->transpile();
                if (! Transpiler::$survey) {
                    $fileName = Transpiler::$target === 'linter' ? $rule['module'] : $name;
                    $extension = Transpiler::$target === 'php' ? '.php' : '.rs';
                    file_put_contents($outDir . '/' . $fileName . $extension, $rule['rust'] . "\n");
                }

                $rules[] = $rule;
                echo "  EMIT    $name\n";
            } catch (Throwable $e) {
                echo "  REFUSE  $name: {$e->getMessage()}\n";
                $refused[] = $name;
            }
        }

        if (! Transpiler::$survey && Transpiler::$target === 'linter') {
            $lint = ModuleEmitter::lintModule($rules);
            file_put_contents($outDir . '/mod.rs', $lint['module']);
            file_put_contents($outDir . '/registration.txt', implode("\n\n", [
                '# Entries for crates/linter/src/rule/mod.rs, inside define_rules! {',
                $lint['variants'],
                '# Fields for crates/linter/src/settings.rs, inside RulesSettings',
                $lint['settings'],
                '# Imports for crates/linter/src/settings.rs',
                $lint['configUses'],
            ]) . "\n");
            echo "\nemitted: " . count($rules) . ', refused: ' . count($refused) . ' (target: ' . Transpiler::$target . ")\n";

            return $refused === [] ? 0 : 1;
        }

        if (! Transpiler::$survey) {
            self::ensureDirectory($outRoot . '/generated');

            if (Transpiler::$target !== 'php') {
                file_put_contents($outRoot . '/generated/mod.rs', ModuleEmitter::module($rules));
            }

            // What the harness needs to attribute a finding, resolved here because this is where the
            // constants behind `->identifier()` and the message formats are already worked out.
            //
            // Attribute on `identifiers`, not on `identifier`: a rule that asks several checks reports under
            // one identifier per check, and `identifier` is only the last of them. A harness filtering on that
            // one measures a single check and reads the rest of the rule's silence as agreement.
            $manifest = [];
            foreach ($rules as $rule) {
                $manifest[$rule['name']] = [
                    'identifier' => $rule['identifier'],
                    'identifiers' => $rule['identifiers'],
                    'messages' => $rule['messages'],
                    // The plugin's constructor parameters, by name, with the rule package's own default.
                    //
                    // A generated plugin carries package defaults so that a generated project stands alone, and
                    // a consumer overrides by constructing it with values in its own worker — which it can read
                    // from `[extension-hosts.<name>.environment]` in `mago.toml`. That works and nothing said
                    // the knobs were there: the parameter names lived only in the generated PHP, so using them
                    // meant reading emitted code.
                    //
                    // Worth having measured rather than as a nicety. Across the two differential corpora, 57 of
                    // 59 disagreements are a consumer's configured threshold against a package default — this
                    // project sets `class: 80, function: 20` where `cognitive-complexity` ships `40` and `9`.
                    // Every one of those closes by passing a value the manifest now names.
                    //
                    // Each default's own JSON type is the parameter's type; no rule in the four packages
                    // defaults one to null, so nothing here is ambiguous between "a string" and "absent".
                    'parameters' => $rule['arguments'],
                ];
            }

            ksort($manifest);
            file_put_contents($outRoot . '/generated/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        echo "\nemitted: " . count($rules) . ', refused: ' . count($refused) . ' (target: ' . Transpiler::$target . ")\n";

        return $refused === [] ? 0 : 1;
    }

    /**
     * Creates an output directory, testing for it rather than suppressing the warning.
     *
     * `@mkdir()` on an existing directory still raises one, and `phpunit.xml` sets `failOnWarning`, so a
     * suppressed diagnostic is invisible on a clean checkout and fatal on a second run into the same
     * directory. Reading the state first says the same thing without one.
     */
    private static function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    /**
     * The rule files to work on, from the paths given and from the project named by --from-config.
     *
     * Discovery is reported before anything is transpiled, because the two numbers answer different
     * questions and a reader needs both: how many rules the project registers, and how many of those this
     * tool could carry. A registered rule that PHPStan itself ships is not a gap, so it is subtracted here
     * rather than counted as a refusal later.
     *
     * @return list<string>
     *
     * @throws Refusal when a project was named that cannot be read, or PHPStan will not start in it
     */
    private static function files(Options $options): array
    {
        $files = RulePaths::expand($options->paths);

        if ($options->fromConfig === null) {
            return $files;
        }

        $registered = RegisteredRules::discover($options->fromConfig);
        $discovered = $registered->portableFiles();

        echo '  CONFIG  ', $registered->configFile, "\n";
        echo '          ', count($registered->rules), ' rules registered, ',
        $registered->coreCount(), " of them PHPStan's own, ",
        $registered->portableCount(), ' to carry across ', count($discovered), " files\n";

        foreach ($registered->duplicated() as $class => $services) {
            // Two services of one class are two configurations. One generated plugin carries one of them,
            // so this is said out loud rather than silently collapsed into a single emitted rule.
            echo '  CONFIG  ', $class, ' is registered ', $services, " times with its own arguments each; one plugin will be emitted\n";
        }

        echo "\n";

        foreach ($discovered as $file) {
            if (! in_array($file, $files, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
