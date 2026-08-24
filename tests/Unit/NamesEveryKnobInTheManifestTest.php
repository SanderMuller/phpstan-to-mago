<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * Every constructor parameter an emitted plugin carries is named in the manifest, under the name it has.
 *
 * A generated plugin carries its package's defaults so that a generated project stands alone, and a consumer
 * overrides by constructing it with values in its own worker — read from `[extension-hosts.<name>.environment]`
 * in `mago.toml`. Using that meant reading the emitted PHP to learn the parameter names, because the manifest
 * did not carry them.
 *
 * Worth a gate rather than a note. Across the two differential corpora, 57 of 59 disagreements are a consumer's
 * configured threshold against a package default — `class: 80, function: 20` here against `40` and `9` in
 * `tomasvotruba/cognitive-complexity` — and every one closes by passing a value. A knob the manifest does not
 * name is a divergence a consumer cannot fix.
 *
 * Asserted both ways round: the manifest names no parameter the constructor lacks, and the constructor takes
 * none the manifest omits. The second direction is the one that regresses — the aggregate's `$required`
 * threshold was emitted and unnamed for as long as the manifest existed.
 */
final class NamesEveryKnobInTheManifestTest extends TestCase
{
    /** @var list<string> */
    private const array PACKAGES = [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
    ];

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverified = false;
    }

    public function test_the_manifest_names_exactly_the_parameters_the_plugin_takes(): void
    {
        $checked = 0;

        foreach (self::PACKAGES as $package) {
            $source = dirname(__DIR__, 2) . '/vendor/' . $package . '/src';
            foreach (RulePaths::expand(is_dir($source) ? [$source] : []) as $file) {
                try {
                    $emitted = (new Transpiler($file))->transpile();
                } catch (Refusal) {
                    continue;
                }

                // The names the emitted constructor actually declares, read out of the emission rather than
                // out of the descriptor the manifest is built from — otherwise both sides come from one place
                // and the comparison proves nothing.
                preg_match_all('/public readonly [a-z|]+ \$([A-Za-z_]+)/', $emitted['rust'], $found);

                sort($found[1]);
                $named = array_keys($emitted['arguments']);
                sort($named);

                $this->assertSame(
                    $found[1],
                    $named,
                    "{$emitted['name']}'s manifest parameters do not match the constructor it emits.",
                );

                ++$checked;
            }
        }

        // Guards the guard: an empty corpus would compare nothing and pass.
        $this->assertGreaterThan(30, $checked);
    }
}
