<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Cli;
use Sandermuller\PhpstanToMago\InstalledRulePackages;
use Sandermuller\PhpstanToMago\Options;
use Sandermuller\PhpstanToMago\PackageCoverage;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\RuleOutcome;
use Sandermuller\PhpstanToMago\StatusPage;
use Sandermuller\PhpstanToMago\StatusReport;
use Sandermuller\PhpstanToMago\Transpiler;
use Sandermuller\PhpstanToMago\WorkerScaffold;

/**
 * The status page and the worker that makes it true.
 *
 * The page reports coverage for a *consumer's* install, which is a different question from the census: the
 * census pins this repository's own corpus so an upstream release arrives as a diff, and this describes
 * whatever is in front of the reader — including a rule package the census has never seen.
 *
 * What is asserted here is the part a snapshot cannot hold. The numbers move with every upstream release, so
 * pinning them would make this a second drift alarm competing with the census; what does not move is that
 * both artefacts read the same model, that discovery is a filesystem walk rather than a manifest guess, and
 * that the generated worker registers exactly the rules the page calls running.
 */
final class ReportsInstalledCoverageTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
        Transpiler::$allowUnverified = false;
    }

    public function test_it_finds_a_rule_package_that_is_not_in_the_curated_corpus(): void
    {
        $names = array_map(
            static fn (InstalledRulePackages $p): string => $p->name,
            InstalledRulePackages::discover(self::ROOT),
        );

        // The census speaks for seven packages this repository curates. Discovery must not be that list, or a
        // consumer's own rule package would be missing from their page with nothing saying so.
        $this->assertContains('symplify/phpstan-rules', $names);
        $this->assertContains('spaze/phpstan-disallowed-calls', $names);
    }

    public function test_it_keeps_only_packages_that_actually_ship_a_rule(): void
    {
        // `extra.phpstan` finds candidates and over-matches: a package can register a PHPStan *extension* and
        // ship no rule at all. The filter is a walk for rule classes, so every package on the page has at
        // least one rule for the transpiler to have an opinion about.
        foreach (InstalledRulePackages::discover(self::ROOT) as $package) {
            $this->assertNotSame([], $package->ruleFiles, $package->name . ' has no rule files');
        }
    }

    public function test_it_refuses_a_directory_with_no_installed_packages(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/no installed packages to read/');

        InstalledRulePackages::discover(sys_get_temp_dir());
    }

    public function test_the_denominators_are_ordered_the_way_the_page_claims(): void
    {
        $coverage = PackageCoverage::forPackage(
            'tomasvotruba/cognitive-complexity',
            self::ROOT . '/vendor/tomasvotruba/cognitive-complexity',
        );

        // ships >= registers >= portable >= emitted. Getting this order wrong is how a coverage figure comes
        // to quote a denominator the tool can never reach.
        $this->assertGreaterThanOrEqual(count($coverage->registered()), $coverage->ships());
        $this->assertGreaterThanOrEqual($coverage->portable(), count($coverage->registered()));
        $this->assertGreaterThanOrEqual($coverage->emitted(), $coverage->portable());
        $this->assertSame($coverage->ships() - count($coverage->registered()), $coverage->unwired());
    }

    public function test_a_refused_rule_carries_the_reason_that_stopped_it(): void
    {
        $coverage = PackageCoverage::forPackage(
            'tomasvotruba/cognitive-complexity',
            self::ROOT . '/vendor/tomasvotruba/cognitive-complexity',
        );

        foreach ($coverage->outcomes as $outcome) {
            if ($outcome->emitted()) {
                $this->assertNull($outcome->reason);

                continue;
            }

            // A count nobody can check is a claim, not a report. Partial coverage is only worth shipping if
            // the gap is legible, so every non-emitting rule names what stopped it.
            $this->assertNotSame('', (string) $outcome->reason, $outcome->name . ' refused without a reason');
            $this->assertDoesNotMatchRegularExpression('/\(line \d+\)/', (string) $outcome->reason);
        }
    }

    public function test_the_page_names_its_target_beside_every_count(): void
    {
        $report = StatusReport::forProject(self::ROOT, 'php');
        $markdown = StatusPage::markdown($report);

        // A count belongs to its configuration. The same rule renders as Rust and refuses as PHP, so a page
        // without its target invites two correct numbers to read as a contradiction.
        $this->assertStringContainsString('for the `php` target', $markdown);
        $this->assertStringContainsString((string) $report->emitted(), $markdown);
    }

    public function test_both_renderers_report_the_same_counts(): void
    {
        $report = StatusReport::forProject(self::ROOT, 'php');

        // Two renderers over one model, which is why they cannot disagree. Asserted rather than assumed,
        // because the HTML builds its rows separately and a copied loop is where they would drift.
        foreach ($report->packages as $package) {
            $claim = sprintf('%d of %d run', $package->emitted(), $package->portable());
            $this->assertStringContainsString($claim, StatusPage::markdown($report));
            $this->assertStringContainsString(sprintf('%d of %d run', $package->emitted(), $package->portable()), StatusPage::html($report));
        }
    }

    public function test_the_html_escapes_what_it_renders(): void
    {
        $html = StatusPage::html(StatusReport::forProject(self::ROOT, 'php'));

        // Refusal reasons carry class names with backslashes and generics with angle brackets. Unescaped,
        // one of those closes a tag and the rest of the page vanishes without an error anywhere.
        $this->assertStringNotContainsString('<PhpParser', $html);
        $this->assertStringContainsString('<!doctype html>', $html);
    }

    public function test_the_worker_registers_exactly_the_rules_the_page_calls_running(): void
    {
        $worker = WorkerScaffold::worker(
            [
                ['name' => 'ClassLikeCognitiveComplexityRule', 'arguments' => ['class' => 40]],
                ['name' => 'FunctionLikeCognitiveComplexityRule', 'arguments' => []],
            ],
            'generated-php',
            'transpiled',
            '/tmp/vendor/autoload.php',
        );

        $this->assertStringContainsString('new ClassLikeCognitiveComplexityRule(),', $worker);
        $this->assertStringContainsString('new FunctionLikeCognitiveComplexityRule(),', $worker);
        $this->assertStringContainsString("require '/tmp/vendor/autoload.php';", $worker);

        // The override point named in the file. 57 of 59 disagreements across two differential corpora were a
        // consumer's threshold against a package default, and every one closes by passing a value here — so
        // the parameter has to be visible without reading emitted code.
        $this->assertStringContainsString('$class = 40', $worker);
    }

    public function test_the_worker_never_writes_the_consumers_mago_config(): void
    {
        $snippet = WorkerScaffold::configSnippet('/tmp/out/worker.php', 'transpiled');

        // A tool that edits a config it does not own silently reverts a hand edit. The snippet is a file to
        // paste from, and it says so.
        $this->assertStringContainsString('does not edit that file', $snippet);
        $this->assertStringContainsString('[extension-hosts.transpiled]', $snippet);
    }

    public function test_a_worker_with_no_rules_refuses_rather_than_writing_an_empty_one(): void
    {
        $this->expectException(Refusal::class);

        // An empty worker loads, registers nothing, and reports nothing — which reads as "the rules found no
        // problems" rather than as "there are no rules".
        WorkerScaffold::worker([], 'generated-php', 'transpiled', '/tmp/vendor/autoload.php');
    }

    public function test_the_page_is_written_off_the_root_so_it_cannot_collide_with_a_project_route(): void
    {
        $out = sys_get_temp_dir() . '/phpstan-to-mago-status-' . bin2hex(random_bytes(6));
        mkdir($out, 0700, true);

        try {
            // Swallowed rather than left to print: the CLI reports to stdout by design, and an unexpected
            // output test is one PHPUnit marks risky.
            ob_start();
            Cli::run(['--status=' . self::ROOT], $out);
            ob_end_clean();

            // A consumer points `--out` at their document root, so the page has to sit on a path of its own:
            // written to the root it would be the site's index, and no project installs a dev dependency
            // expecting that.
            $this->assertFileExists($out . '/phpstan-to-mago/index.html');
            $this->assertFileDoesNotExist($out . '/index.html');
            $this->assertFileDoesNotExist($out . '/status.html');

            // Beside the directory rather than inside it, so a served copy answers
            // `{host}/phpstan-to-mago.md` next to the page at `{host}/phpstan-to-mago`. Both names carry the
            // package, which is what keeps either from colliding with a path the consumer owns.
            $this->assertFileExists($out . '/phpstan-to-mago.md');
            $this->assertFileDoesNotExist($out . '/phpstan-to-mago/status.md');
        } finally {
            $written = glob($out . '/phpstan-to-mago*');
            foreach ($written === false ? [] : $written as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            $inside = glob($out . '/phpstan-to-mago/*');
            foreach ($inside === false ? [] : $inside as $file) {
                unlink($file);
            }

            @rmdir($out . '/phpstan-to-mago');
            @rmdir($out);
        }
    }

    public function test_the_status_flag_parses_with_and_without_a_path(): void
    {
        $this->assertSame('/srv/app', Options::parse(['--status=/srv/app'])->status);
        $this->assertNotNull(Options::parse(['--status'])->status);
        $this->assertNull(Options::parse(['src/Rule.php'])->status);
    }

    public function test_a_never_rule_carries_no_needs_list(): void
    {
        $coverage = PackageCoverage::forPackage(
            'hihaho/phpstan-rules',
            self::ROOT . '/vendor/hihaho/phpstan-rules',
        );

        foreach ($coverage->outcomes as $outcome) {
            if ($outcome->verdict !== RuleOutcome::NEVER) {
                continue;
            }

            // A needs list is what a rule's body would take. This rule's body is not the obstacle, so printing
            // one invites exactly the sizing the verdict exists to prevent.
            $this->assertSame([], $outcome->needs, $outcome->name . ' carries needs it can never use');
        }
    }
}
