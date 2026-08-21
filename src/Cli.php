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
     * @param list<string> $argv rule source paths or directories, plus any of --target, --survey, --examples
     *
     * @return int 0 when every rule was emitted
     */
    public static function run(array $argv, string $outRoot): int
    {
        try {
            $options = Options::parse($argv);
        } catch (Refusal $refusal) {
            echo '  REFUSE  ', $refusal->getMessage(), "\n";

            return 1;
        }

        Transpiler::$target = $options->target;
        Transpiler::$survey = $options->survey;
        Transpiler::$allowUnverified = $options->unverified;
        if ($options->examplesDir !== null) {
            Transpiler::$examplesDir = $options->examplesDir;
        }

        $files = RulePaths::expand($options->paths);

        $outDir = $options->outDir($outRoot);
        @mkdir($outDir, 0777, true);

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
            @mkdir($outRoot . '/generated', 0777, true);
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
                ];
            }

            ksort($manifest);
            file_put_contents($outRoot . '/generated/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        echo "\nemitted: " . count($rules) . ', refused: ' . count($refused) . ' (target: ' . Transpiler::$target . ")\n";

        return $refused === [] ? 0 : 1;
    }
}
