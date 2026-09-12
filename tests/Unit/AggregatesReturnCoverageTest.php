<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\PhpstanReport;
use Sandermuller\PhpstanToMago\Transpiler;
use Sandermuller\PhpstanToMago\Vocabulary;

/**
 * The return-type aggregate, computed two ways, through the plugin the transpiler actually emits.
 *
 * The sibling of {@see AggregatesConstantCoverageTest}, and the metric that had no such test until an anchor
 * bug shipped. `paramTypeCoverage`, `constantTypeCoverage` and `declareCoverage` each compared findings by
 * line; `returns` compared only totals, through {@see CountsReturnsLikeTheCollectorTest} — and an anchor one
 * line out moves no total. So the suite stayed green while the port reported every attributed method a line
 * below the original, 33 times on `Illuminate\Database\Eloquent`, until the corpus differential found it.
 *
 * The lines are therefore the whole point here. `ReturnTypeDeclarationCollector` reports
 * `$node->getLine()` on the function-like, and php-parser's start line for an attributed method is the
 * attribute's rather than the `public function` keyword's.
 *
 * **Fixture agreement is half the claim.** The corpus half is `run-coverage-corpus.php --metric=returns`,
 * which counted 18307 of 18307 declarations on one consumer and 8526 of 8526 on another.
 * `Vocabulary::ACCEPTED_DIVERGENCE['returns']` states that, and the emitted plugin carries it.
 *
 * The fixture earns each of its four files: one declared return type, one plain untyped method where both
 * anchors coincide, one attributed method where they do not, and one closure — which the collector counts
 * because its node type is `FunctionLike`, and which has no name for an anchor to read.
 */
#[Group('engine')]
final class AggregatesReturnCoverageTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/aggregate/project-returns';

    private const string IDENTIFIER = 'typeCoverage.returnTypeCoverage';

    private string $sandbox;

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $root = dirname(__DIR__, 2);
        $this->sandbox = sys_get_temp_dir() . '/phpstan-to-mago-returns-' . getmypid();
        if (! is_dir($this->sandbox . '/src')) {
            mkdir($this->sandbox . '/src', 0o777, true);
        }

        $files = glob(self::FIXTURE . '/src/*.php');
        foreach ($files === false ? [] : $files as $file) {
            copy($file, $this->sandbox . '/src/' . basename($file));
        }

        file_put_contents($this->sandbox . '/plugin.php', $this->emitted());

        foreach ([$root . '/vendor' => '/vendor', $root . '/vendor/bin/mago' => '/mago'] as $target => $link) {
            if (! is_link($this->sandbox . $link)) {
                symlink($target, $this->sandbox . $link);
            }
        }

        file_put_contents($this->sandbox . '/worker.php', <<<PHP
            <?php

            declare(strict_types=1);

            // A notice on stdout corrupts the extension frame — mago reads binary frames there.
            ini_set('display_errors', 'stderr');

            use Mago\\Sdk\\Extension;
            use Mago\\Sdk\\Worker;
            use Transpiled\\ReturnTypeCoverageRule;

            require '{$root}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'transpiled/coverage',
                name: 'Coverage',
                version: '0.0.0',
                analyzerPlugins: [new ReturnTypeCoverageRule(99)],
            )))->run();
            PHP);

        file_put_contents($this->sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src"]

            [extension-hosts.coverage]
            command = ["php", "worker.php"]
            TOML);

        file_put_contents($this->sandbox . '/phpstan.neon', <<<'NEON'
            parameters:
                level: 0
                paths:
                    - src
                type_coverage:
                    param_type: 0
                    return_type: 99
                    property_type: 0
                    constant_type: 0
                    declare: 0
            NEON);
    }

    public function test_the_rule_emits_and_carries_what_it_was_measured_at(): void
    {
        $note = Vocabulary::ACCEPTED_DIVERGENCE['returns']['note'];

        $this->assertStringContainsString('18307 of 18307', $note);
        $this->assertStringContainsString('--metric=returns', $note);

        // A reader of the generated plugin has no reason to open this repository, so the measurement travels
        // with it. The parameter aggregate carries its bound the same way. Compared with whitespace folded,
        // because the emitter wraps the note into a docblock and the line breaks it inserts are its own.
        $folded = static fn (string $text): string => (string) preg_replace('/\s+/', ' ', str_replace(['*', '/'], ' ', $text));

        $this->assertStringContainsString($folded($note), $folded($this->emitted()));
    }

    public function test_the_reimplementation_agrees_with_the_real_rule_on_this_fixture(): void
    {
        $original = $this->phpstanFindings();
        $port = $this->magoFindings();

        $this->assertSame(
            array_keys($original),
            array_keys($port),
            'The two disagree about which files hold an untyped class constant.',
        );

        // Compared as `line: message`, the way the fires gate compares an ordinary rule. The message carries
        // the whole measurement — total, typed and percentage — so this is more than a file set, and the line
        // is what says a wrapped declaration is reported where the original reports it.
        //
        // Sorted within each file, because neither tool promises an order: the original walks the collected
        // data and this walks the codebase's constant list, which is alphabetical by name.
        /**
         * @param list<string> $lines
         *
         * @return list<string>
         */
        $sorted = static function (array $lines): array {
            sort($lines);

            return $lines;
        };

        $this->assertSame(array_map($sorted, $original), array_map($sorted, $port));
    }

    public function test_counts_and_skips_exactly_what_the_original_does(): void
    {
        $findings = $this->magoFindings();
        $messages = array_merge(...array_values($findings));

        // Five declarations the collector's `FunctionLike` node type reaches: `done()` and `make()` carry a
        // return type, `open()`, `tagged()` and the closure inside `make()` do not. Two of five, 40.0 %.
        $this->assertNotSame([], $messages);
        // Four declarations, not five: `done()` and `make()` carry a return type, `open()` and `tagged()` do
        // not, and the closure inside `make()` is counted by neither engine. That last one was a guess when
        // this fixture was written and the run corrected it — the aggregate walks the codebase's *method*
        // list, so a closure never reaches it, and PHPStan agrees at 4 possible.
        $this->assertStringContainsString(
            'Out of 4 possible return types, only 2 - 50.0 %',
            $messages[0],
        );

        // `Typed.php` is absent because its declaration is counted and never reported. `Anonymous.php` is
        // absent because its closure is not counted at all, which is what that file is here to pin.
        $this->assertSame(['Attributed.php', 'Untyped.php'], array_keys($findings));

        // The anchor, and the reason this fixture exists. `#[Marker]` is on line 23 and `public function
        // tagged()` on line 24; the original reports 23, so the port has to. Reading the method's *name*
        // gives 24, which is what shipped and what the corpus differential caught 33 times.
        $this->assertCount(1, $findings['Attributed.php']);
        $this->assertStringStartsWith('23: ', $findings['Attributed.php'][0]);
    }

    private function emitted(): string
    {
        $rust = (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/ReturnTypeCoverageRule.php',
        ))->transpile()['rust'];

        return is_string($rust) ? $rust : throw new RuntimeException('the transpiler produced no source');
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

            $file = basename((string) ($issue['annotations'][0]['span']['file_id']['name'] ?? ''));
            // Mago's JSON line is 0-based; PHPStan's is 1-based, and the two are compared.
            $line = ((int) ($issue['annotations'][0]['span']['start']['line'] ?? 0)) + 1;
            $findings[$file][] = $line . ': ' . ($issue['message'] ?? '');
        }

        ksort($findings);

        return $findings;
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
            'ReturnTypeCoverageRule',
        );
    }

    /** @param list<string> $command */
    private function execute(array $command): string
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->sandbox,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('could not run ' . implode(' ', $command));
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output === '' ? $errors : $output;
    }
}
