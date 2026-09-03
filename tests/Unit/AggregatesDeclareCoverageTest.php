<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\PhpstanReport;
use Sandermuller\PhpstanToMago\Transpiler;
use Sandermuller\PhpstanToMago\Vocabulary;

/**
 * The declare aggregate, computed two ways.
 *
 * The sibling of {@see AggregatesTypeCoverageTest} for the one coverage question that is not about a
 * declaration in the codebase but about a statement in a file. That difference is why it agrees where the
 * return metric does not: the real collector emits one record per analysed file, so there is no trait
 * multiplicity to reproduce and no ancestor lookup to answer.
 *
 * **Fixture agreement is half the claim.** The corpus half is `run-coverage-corpus.php --metric=declares`,
 * which counted 2932 of 2932 files on one Laravel consumer and 1895 of 1895 on another, agreeing on the
 * percentage as well as the count. `Vocabulary::ACCEPTED_DIVERGENCE['declares']` states that, and the
 * emitted plugin carries it.
 *
 * The fixture earns each of its four files: one covered, one with no `declare`, one with a `declare` that is
 * not `strict_types`, and one with `strict_types=0`. The last is what makes the `=1` load-bearing —
 * matching `strict_types` alone takes the count from 1 typed of 4 to 2, and this comparison fails on it.
 */
final class AggregatesDeclareCoverageTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/aggregate/project-declares';

    private const string IDENTIFIER = 'typeCoverage.declareCoverage';

    private string $sandbox;

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $root = dirname(__DIR__, 2);
        $this->sandbox = sys_get_temp_dir() . '/phpstan-to-mago-declare-' . getmypid();
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
            use Transpiled\\DeclareCoverageRule;

            require '{$root}/vendor/autoload.php';
            require __DIR__ . '/plugin.php';

            (new Worker(new Extension(
                identifier: 'transpiled/coverage',
                name: 'Coverage',
                version: '0.0.0',
                analyzerPlugins: [new DeclareCoverageRule(99)],
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
                    return_type: 0
                    property_type: 0
                    constant_type: 0
                    declare: 99
            NEON);
    }

    public function test_the_rule_emits_and_carries_what_it_was_measured_at(): void
    {
        $note = Vocabulary::ACCEPTED_DIVERGENCE['declares']['note'];

        $this->assertStringContainsString('2932 of 2932', $note);
        $this->assertStringContainsString('--metric=declares', $note);

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
            'The two disagree about which files are missing a strict-types declare.',
        );

        // The message carries the whole measurement — total, typed and percentage — so comparing it compares
        // more than the file set. Lines are excluded on purpose: PHPStan reports -1 for a finding about a
        // file rather than a position in one, and mago has no way to report a finding without a span, so the
        // port anchors on the file's first node. That is a rendering difference and not a disagreement.
        $this->assertSame(
            array_map(array_values(...), $original),
            array_map(array_values(...), $port),
        );
    }

    public function test_counts_and_skips_exactly_what_the_original_does(): void
    {
        $messages = array_merge(...array_values($this->magoFindings()));

        // 4 files, 1 covered. `OtherDeclare` has a `declare` that is not `strict_types`, and
        // `ExplicitlyOff` has `strict_types=0` — neither counts, and both are reported.
        $this->assertNotSame([], $messages);
        $this->assertStringContainsString('Out of 4 possible declare(strict_types=1), only 1 - 25.0 %', $messages[0]);
        $this->assertSame(
            ['ExplicitlyOff.php', 'Loose.php', 'OtherDeclare.php'],
            array_keys($this->magoFindings()),
        );
    }

    private function emitted(): string
    {
        $rust = (new Transpiler(
            dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Rules/DeclareCoverageRule.php',
        ))->transpile()['rust'];

        return is_string($rust) ? $rust : throw new RuntimeException('the transpiler produced no source');
    }

    /** @return array<string, list<string>> */
    private function magoFindings(): array
    {
        $output = $this->execute(['./mago', 'analyze', '--reporting-format', 'json']);

        /** @var array{issues?: list<array{code?: string, message?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}}}>}>}|null $decoded */
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
            $findings[$file][] = (string) ($issue['message'] ?? '');
        }

        ksort($findings);

        return $findings;
    }

    /** @return array<string, list<string>> */
    private function phpstanFindings(): array
    {
        $root = dirname(__DIR__, 2);

        $findings = PhpstanReport::findings(
            $this->execute([
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '--configuration=phpstan.neon',
            ]),
            self::IDENTIFIER,
            'DeclareCoverageRule',
        );

        // `PhpstanReport` prefixes each message with its line, which is `-1` here for every finding.
        return array_map(
            static fn (array $lines): array => array_map(
                static fn (string $line): string => (string) preg_replace('/^-?\d+: /', '', $line),
                $lines,
            ),
            $findings,
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
