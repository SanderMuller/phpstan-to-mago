<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

/**
 * What `is_callable()` narrowing hands a plugin, and whether the port's predicate reads it.
 *
 * Two assertions over one run, and the split between them is the whole point. mago sets `callable: true` on
 * the string atomic's refinement; `Types::typeIsCallable()` has to read that flag, because nothing else in the
 * atomic says so.
 *
 * **`ScalarType::__toString()` returns `$this->kind->value` and nothing else.** A `callable-string` therefore
 * renders as `string`, exactly like an un-narrowed one, and a probe reading `(string) $type` cannot tell the
 * two apart. One did, and it produced a six-row table that read as a mago narrowing bug — the wrong "why",
 * built on an instrument that was measuring the rendering rather than the type. This test asserts the flag
 * itself for that reason: the rendering is not evidence about narrowing and must not be used as any.
 *
 * The `is_string` row is the control. Without it a green run is equally consistent with the flag being read
 * and with it being hard-coded true, and the same six-row table would pass either way.
 */
#[CoversNothing]
#[Group('engine')]
final class ReadsTheCallableStringRefinementTest extends TestCase
{
    /** What mago sets on the string atomic's refinement at each of the six positions. */
    private const array NARROWED = [
        'fromCallableOrString' => true,
        'fromMixed' => false,
        'fromMixedUnderIsString' => false,
        'fromString' => true,
        'fromStringOrClosure' => true,
        'fromStringOrInt' => true,
    ];

    /**
     * And what the port answers there.
     *
     * `fromMixed` is the only row a rendered name gets right: `mixed` narrows to a `CallableType`, which the
     * predicate has always recognised. The four `true` rows are the ones a rule asking `isCallable()->yes()`
     * used to get wrong — `Pluralizer`'s four-string union and Carbon's `callable|string $function = 'round'`
     * are the corpus sites, and PHPStan exempts both.
     */
    private const array CALLABLE = [
        'fromCallableOrString' => true,
        'fromMixed' => true,
        'fromMixedUnderIsString' => false,
        'fromString' => true,
        'fromStringOrClosure' => true,
        'fromStringOrInt' => true,
    ];

    public function test_mago_sets_the_callable_flag_on_the_narrowed_string_atomic(): void
    {
        $read = $this->readings();

        $this->assertSame(
            self::NARROWED,
            array_map(static fn (array $row): bool => $row['flag'], $read),
            'mago no longer narrows a string to a callable-string at the plugin boundary. Read the flag, never '
            . '`(string) $type` — a callable-string renders as `string`.',
        );
    }

    public function test_the_port_answers_callable_wherever_mago_narrowed_one(): void
    {
        $read = $this->readings();

        $this->assertSame(
            self::CALLABLE,
            array_map(static fn (array $row): bool => $row['callable'], $read),
            'Types::typeIsCallable() disagrees with the refinement mago set, so a rule exempting a callable '
            . 'reports one.',
        );
    }

    /**
     * One mago run, keyed by the method the assignment sits in.
     *
     * @return array<string, array{flag: bool, callable: bool}>
     */
    private function readings(): array
    {
        $sandbox = $this->sandbox();
        $output = $this->capture([$this->root() . '/vendor/bin/mago', 'analyze'], $sandbox);

        $result = $sandbox . '/readings.txt';
        if (! is_file($result)) {
            throw new RuntimeException("The probe worker wrote nothing, so mago never reached it:\n" . $output);
        }

        $rows = [];
        foreach (explode("\n", trim((string) file_get_contents($result))) as $line) {
            if ($line === '') {
                continue;
            }

            [$method, $flag, $callable] = explode(' ', $line);
            $rows[$method] = ['flag' => $flag === '1', 'callable' => $callable === '1'];
        }

        ksort($rows);

        return $rows;
    }

    /** The fixture with a probe worker over it, which reports the flag and the predicate side by side. */
    private function sandbox(): string
    {
        $sandbox = sys_get_temp_dir() . '/callable-refinement-' . bin2hex(random_bytes(6));
        mkdir($sandbox . '/src', 0o777, true);
        copy(__DIR__ . '/../Fixtures/CallableRefinement/src/Narrow.php', $sandbox . '/src/Narrow.php');
        symlink($this->root() . '/vendor', $sandbox . '/vendor');

        $autoload = $this->root() . '/vendor/autoload.php';
        file_put_contents($sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            require '{$autoload}';

            use Mago\Sdk\Analyzer\FileAnalysisRequirement;
            use Mago\Sdk\Analyzer\NodeAnalysisContext;
            use Mago\Sdk\Analyzer\NodeAnalysisHook;
            use Mago\Sdk\Analyzer\Plugin;
            use Mago\Sdk\Analyzer\PluginDefinition;
            use Mago\Sdk\Analyzer\PluginRegistry;
            use Mago\Sdk\Analyzer\Type\ScalarType;
            use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
            use Mago\Sdk\Analyzer\Type\StringType;
            use Mago\Sdk\Syntax\NodeKind;
            use Sandermuller\PhpstanToMago\Runtime\Support;

            final class Probe implements Plugin, NodeAnalysisHook
            {
                public function getDefinition(): PluginDefinition
                {
                    return new PluginDefinition('probe/refinement', 'Probe', 'refinement');
                }

                public function register(PluginRegistry \$registry): void
                {
                    \$registry->registerNodeAnalysisHook(\$this);
                }

                public function getTargets(): array
                {
                    return [NodeKind::Assignment];
                }

                public function getRequirements(): array
                {
                    return [
                        FileAnalysisRequirement::ExpressionTypes,
                        FileAnalysisRequirement::TargetSubtree,
                        FileAnalysisRequirement::SourceText,
                    ];
                }

                public function analyze(NodeAnalysisContext \$context): void
                {
                    \$method = Support::enclosingFunctionName(\$context, \$context->node);
                    foreach (\$context->source->getChildren(\$context->node) as \$child) {
                        \$text = trim((string) \$context->source->getText(\$child));
                        if (\$text === '=' || str_starts_with(\$text, '\$u')) {
                            continue;
                        }

                        \$type = Support::expressionType(\$context, \$child);
                        \$flag = false;
                        foreach (\$type === null ? [] : \$type->atomicTypes as \$atomic) {
                            if (\$atomic instanceof ScalarType
                                && \$atomic->kind === ScalarTypeKind::String
                                && \$atomic->refinement instanceof StringType
                                && \$atomic->refinement->callable
                            ) {
                                \$flag = true;
                            }
                        }

                        file_put_contents(
                            __DIR__ . '/readings.txt',
                            sprintf("%s %d %d\\n", \$method, \$flag ? 1 : 0, Support::typeIsCallable(\$context, \$type) ? 1 : 0),
                            FILE_APPEND,
                        );
                    }
                }
            }

            (new Mago\Sdk\Worker(new Mago\Sdk\Extension(
                'probe/refinement', 'Refinement probe', '0.0.0',
                analyzerPlugins: [new Probe()],
            )))->run();
            PHP);

        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.probe]
            command = ["php", "worker.php"]
            TOML);

        return $sandbox;
    }

    /**
     * @param list<string> $command
     */
    private function capture(array $command, string $sandbox): string
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
        proc_close($process);

        return $stdout . $stderr;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
