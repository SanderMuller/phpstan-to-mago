<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Tests\Support\PhpstanReport;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * The aggregate-rule differential: a coverage percentage, computed two ways.
 *
 * `type-coverage`'s rules are the shape that has no per-file translation — PHPStan collects a fact per file and
 * a second rule reduces the collection. So this compares the reimplementation against the *real rule* rather
 * than against a snapshot of what it was expected to say. A percentage that agrees on a fixture nobody tuned
 * is the only evidence worth having here.
 *
 * **Scope, stated up front: this establishes fixture-level agreement, not corpus-level.** The same counting
 * agreed exactly here and then disagreed on a 585-file dependency tree — PHPStan counted 4057 parameters with
 * 1994 typed where the port counted 3079 with 2927. Both causes are fixed since, and the counting now agrees
 * on ten controls (see {@see CountsParametersLikeTheCollectorTest}) and comes within 11 of 11108 on one real
 * consumer and 63 of 11375 on another — close, and not equal, so the transpiler still refuses the rule. These
 * tests hold the design still while that last gap is worked out.
 *
 * The plugin under test is a hand-written reference of the shape the transpiler would emit.
 *
 * The fixture project earns each of its parts. It holds a trait and a class that uses it, a magic method, a variadic
 * parameter and a constructor, because a weaker fixture agreed while exercising none of the filters: removing
 * the magic-method skip, the variadic skip and a dedup all left it green. Each of those now changes the total
 * and breaks the comparison, which is the only reason to trust that the filters are doing anything.
 */
final class AggregatesTypeCoverageTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/aggregate';

    private const string IDENTIFIER = 'typeCoverage.paramTypeCoverage';

    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpstan-to-mago-aggregate';
        if (! is_dir($this->sandbox . '/src')) {
            mkdir($this->sandbox . '/src', 0o777, true);
        }

        $root = dirname(__DIR__, 2);
        $project = glob(self::FIXTURE . '/project/src/*.php');
        foreach ($project === false ? [] : $project as $file) {
            copy($file, $this->sandbox . '/src/' . basename($file));
        }

        copy(self::FIXTURE . '/ParamTypeCoverageRule.php', $this->sandbox . '/plugin.php');

        if (! is_link($this->sandbox . '/vendor')) {
            symlink($root . '/vendor', $this->sandbox . '/vendor');
        }

        if (! is_file($this->sandbox . '/mago')) {
            symlink($root . '/vendor/bin/mago', $this->sandbox . '/mago');
        }

        file_put_contents($this->sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            // A notice on stdout corrupts the extension frame — mago reads binary frames there, and the first
            // bytes of a deprecation message arrive as `invalid extension frame magic`. One deprecated
            // function in a vendored dependency turned 284 passing tests into 107 errors under
            // `--prefer-lowest`, and nothing in the failure named the cause.
            ini_set('display_errors', 'stderr');

            use Mago\\Sdk\\Extension;
            use Mago\\Sdk\\Worker;
            use Transpiled\\ParamTypeCoverageRule;

            require '{$root}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'transpiled/coverage',
                name: 'Coverage',
                version: '0.0.0',
                analyzerPlugins: [new ParamTypeCoverageRule()],
            )))->run();
            PHP);

        file_put_contents($this->sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.coverage]
            command = ["php", "worker.php"]
            TOML);

        // The extension installer already registers type-coverage, so including its neon here would register
        // `MethodNodeAnalyser` twice and PHPStan would abort before analysing — the conflict `CLAUDE.md`
        // records. Only the parameters are set.
        file_put_contents($this->sandbox . '/phpstan.neon', <<<'NEON'
            parameters:
                level: 0
                paths:
                    - src
                type_coverage:
                    param_type: 99
                    return_type: 0
                    property_type: 0
                    constant_type: 0
                    declare: 0
            NEON);
    }

    protected function tearDown(): void
    {
        Transpiler::$allowUnverified = false;
    }

    public function test_the_transpiler_refuses_the_rule_until_a_corpus_differential_agrees(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverified = false;

        // The emission works — it produced this fixture — and is deliberately not mapped, because fixture
        // agreement turned out not to generalise. Refusing is the honest state, and this pins it so the
        // mapping cannot be restored without the corpus differential that justifies it.
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/not equal to it: on hihaho\/app/');

        (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        ))->transpile();
    }

    public function test_the_emission_still_works_behind_the_opt_in(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverified = true;

        $emitted = (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        ))->transpile()['rust'];

        // The whole loop still runs: the rule's source is read, the collector is recognised, the threshold and
        // message come from the package, and an after-analysis plugin comes out. What is withheld is the
        // default, not the capability.
        $this->assertStringContainsString('implements AfterAnalysisHook, Plugin', $emitted);
        $this->assertStringContainsString('TypeCoverage::parameters($context)', $emitted);
        $this->assertStringContainsString('public readonly float $required = 99', $emitted);
        $this->assertStringContainsString("'typeCoverage.paramTypeCoverage'", $emitted);
        $this->assertStringContainsString('Out of %d possible param types', $emitted);
    }

    public function test_the_reimplementation_agrees_with_the_real_rule_on_this_fixture(): void
    {
        $this->assertSame(
            $this->phpstanFindings(),
            $this->magoFindings(),
            'The reimplemented aggregate and the rule it came from disagree on this fixture. Corpus-scale '
            . 'agreement is a separate claim and is not established.',
        );
    }

    public function test_counts_and_skips_exactly_what_the_original_does(): void
    {
        $findings = $this->magoFindings();
        $messages = array_merge(...array_values($findings));

        // 7 = two parameters in the trait's method, two in the class's own, one promoted constructor
        // parameter, one before a variadic — and nothing from the magic method or the variadic itself.
        $this->assertNotSame([], $messages);
        $this->assertStringContainsString('Out of 7 possible param types, only 2 - 28.5 %', $messages[0]);

        $this->assertArrayHasKey(
            'Shared.php',
            $findings,
            'A declaration the trait contributes is reported in the trait, which is where PHPStan reports it.',
        );
    }

    /** @return array<string, list<string>> */
    private function magoFindings(): array
    {
        $output = $this->execute(['./mago', 'analyze', '--reporting-format', 'json']);

        /** @var array{issues?: list<array{code?: string, message?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}, start?: array{line?: int}}}>}>}|null $decoded */
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("mago produced no JSON:\n" . $output);
        }

        $findings = [];
        foreach ($decoded['issues'] ?? [] as $issue) {
            if (! str_contains((string) ($issue['code'] ?? ''), self::IDENTIFIER)) {
                continue;
            }

            $span = $issue['annotations'][0]['span'] ?? [];
            $file = basename((string) ($span['file_id']['name'] ?? ''));
            // Mago's JSON line is 0-based where PHPStan's is 1-based.
            $findings[$file][] = (((int) ($span['start']['line'] ?? 0)) + 1) . ': ' . ($issue['message'] ?? '');
        }

        return $this->sorted($findings);
    }

    /** @return array<string, list<string>> */
    private function phpstanFindings(): array
    {
        $root = dirname(__DIR__, 2);

        return PhpstanReport::findings(
            $this->execute([
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '--configuration=phpstan.neon',
            ]),
            self::IDENTIFIER,
            'ParamTypeCoverageRule',
        );
    }

    /** @param list<string> $command */
    private function execute(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->sandbox);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Both tools exit non-zero when they find something, which is the expected outcome here.
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
            sort($lines);
            $findings[$file] = $lines;
        }

        ksort($findings);

        return $findings;
    }
}
