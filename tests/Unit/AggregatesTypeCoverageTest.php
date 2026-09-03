<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\PhpstanReport;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;
use Sandermuller\PhpstanToMago\Transpiler;
use Sandermuller\PhpstanToMago\Vocabulary;

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
 * on thirteen controls, with a fourteenth pinning the one divergence
 * (see {@see CountsParametersLikeTheCollectorTest}). What is left on a real consumer is
 * +81 of 13694 and +37 of 11428, all of it one unportable cause, so the rule is emitted with that bound
 * rather than refused: {@see Vocabulary::ACCEPTED_DIVERGENCE} states it, `tests/Support/run-coverage-corpus.php`
 * is the gate that fails when a corpus run leaves it, and the `reflection-extension` control pins the
 * mechanism in CI.
 *
 * The plugin under test *is* the transpiler's output, asserted byte for byte below. It began as a hand-written
 * reference of the shape the emission would take, which was the right shape while the rule was refused and the
 * wrong one the moment it emitted: a reference that drifts from the emission gates a plugin nobody ships.
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
        $this->sandbox = sys_get_temp_dir() . '/phpstan-to-mago-aggregate-' . getmypid();
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

    /**
     * The rule emits by default, and the plugin says what it is off by.
     *
     * It was refused for as long as the corpus gap had no named cause. Every part of that gap now traces to
     * `ClassReflection::hasMethod()` answered by reflection extensions a Mago plugin cannot reproduce, so the
     * honest outcome is a stated bound — refusing forever on something the port cannot close blocks the rule
     * permanently for nothing. The number belongs in the generated file, because a reader of a plugin has no
     * reason to know a bound was measured and a coverage percentage quietly 1% off is the plausible-but-wrong
     * shape to design against.
     */
    public function test_the_rule_emits_and_carries_the_divergence_it_is_emitted_with(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverified = false;

        $emitted = (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        ))->transpile()['rust'];

        // The whole loop runs: the rule's source is read, the collector is recognised, and the threshold and
        // message come from the package rather than from here.
        $this->assertStringContainsString('implements AfterAnalysisHook, Plugin', $emitted);
        $this->assertStringContainsString('TypeCoverage::parameters($context)', $emitted);
        $this->assertStringContainsString('public readonly float $required = 99', $emitted);
        $this->assertStringContainsString("'typeCoverage.paramTypeCoverage'", $emitted);
        $this->assertStringContainsString('Out of %d possible param types', $emitted);

        // Both figures, because one of them alone is what a reader would take as a bound. +1 of 17635 is
        // `laravel/framework`'s own `Illuminate` as it stands; 1.11% is the two Laravel applications, and it
        // is the older of the two — measured before `@mixin` was followed and not re-measured since, which
        // the note has to keep saying or the smaller vendor figure reads as covering an application too.
        $this->assertStringContainsString('+1 of 17635 declarations', $emitted);
        $this->assertStringContainsString('1.11% at most on the two Laravel *applications*', $emitted);
        $this->assertStringContainsString('not re-measured since', $emitted);
        // And what the +1 is, by name. A residue nobody can name is how +1 becomes +1310 again unnoticed.
        $this->assertStringContainsString('PhpRedisConnection::hscan()', $emitted);
        $this->assertStringContainsString('run-coverage-corpus.php', $emitted);
    }

    /**
     * And a metric with no accepted divergence carries no note.
     *
     * Without this the docblock could be unconditional and every test above would still pass, which would put
     * a bound on an aggregate nobody measured — the opposite of the point.
     *
     * Asked about a name that is deliberately not a metric, because every metric is measured now. It asked
     * about `returns` and then about `constants`, and each stopped being an unmeasured metric the day its
     * differential passed — a test whose subject can graduate out from under it is one that quietly stops
     * checking anything, so the subject is one that cannot graduate.
     *
     * The pair of assertions is what keeps it honest: the first says the name really is unmeasured, so the
     * second is not passing because the note happens to be empty for a measured one.
     */
    public function test_an_aggregate_with_no_stated_divergence_carries_no_note(): void
    {
        $note = new ReflectionMethod(Transpiler::class, 'divergenceNote');

        $this->assertArrayNotHasKey('never-measured', Vocabulary::ACCEPTED_DIVERGENCE);
        $this->assertSame('', $note->invoke(new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        ), 'never-measured'));
    }

    /**
     * The plugin this test runs is the emission, not a copy of it that once matched.
     *
     * Everything below runs `tests/Fixtures/aggregate/ParamTypeCoverageRule.php` under real mago. That file is
     * only evidence about the shipped rule while it stays identical to what the transpiler emits, and nothing
     * said so until the rule started emitting by default.
     */
    public function test_the_plugin_under_test_is_what_the_transpiler_emits(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $emitted = (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        ))->transpile()['rust'];

        $this->assertSame(
            file_get_contents(self::FIXTURE . '/ParamTypeCoverageRule.php'),
            $emitted . "\n",
            'The plugin the rest of this file runs is no longer what the transpiler emits, so it proves '
            . 'nothing about the shipped rule.',
        );
    }

    /**
     * PHPStan's own report reaches the differential, not a tool wrapper's summary of it.
     *
     * `laravel/pao` is a dev dependency that autoloads through a composer `files` entry and rewrites
     * `phpstan analyse` whenever an agent is driving the terminal: it forces `--error-format=json`, silences
     * stdout, and prints its own `{"tool": "phpstan", ...}` envelope instead. Every sandbox here symlinks this
     * repository's `vendor`, so PHPStan loads that autoloader and the rewrite applies.
     *
     * The envelope *caps* how many errors it lists, which makes it useless as the original side of a
     * differential. {@see Subprocess} switches the wrapper off; this asserts the switch works, because the
     * suite would otherwise stay green while comparing against a truncated original — the one failure mode
     * `PhpstanReport` exists to refuse, and the one that produced a spurious full-suite failure once.
     */
    public function test_the_original_side_is_phpstans_own_uncapped_report(): void
    {
        $root = dirname(__DIR__, 2);
        $output = $this->execute([
            $root . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=phpstan.neon',
        ]);

        // `totals` is PHPStan's own key and appears in no wrapper envelope.
        $this->assertStringContainsString('"totals"', $output, 'A tool wrapper answered instead of PHPStan, and its output caps the errors it lists.');
        $this->assertStringNotContainsString('"tool":"phpstan"', $output);
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
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->sandbox, Subprocess::environment());
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
