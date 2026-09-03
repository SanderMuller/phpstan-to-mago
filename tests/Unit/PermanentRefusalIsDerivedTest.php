<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sandermuller\PhpstanToMago\AggregateRule;
use SplFileInfo;

/**
 * The one `NEVER` mark that depends on something outside the rule, and why it cannot go stale.
 *
 * Three refusals are marked permanent, and the census drops them from its denominator. Two are permanent by
 * their own body: a rule that writes a file, and one that hands a synthesised node back to PHPStan's own
 * analysis. Nothing outside those rules changes either.
 *
 * The third is not. A collector is refused because every rule that *currently* consumes it reports nothing,
 * so its permanence is a fact about its consumers. A peer session named this as the dangerous kind of mark —
 * the one that removes a rule from the denominator and stops anyone looking again.
 *
 * It cannot go stale, and this is the proof rather than the argument: the answer is re-derived from the
 * consumer files on every run, so adding one that builds a rule error flips it, the mark disappears by
 * itself, and the census drift alarm fires on the line that changed.
 */
#[CoversClass(AggregateRule::class)]
final class PermanentRefusalIsDerivedTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root === '' || ! is_dir($this->root)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function test_a_collector_whose_only_consumer_writes_a_file_is_permanent(): void
    {
        $collector = $this->package(reportingConsumer: false);

        $this->assertTrue(AggregateRule::onlyFeedsAWriter($collector), 'A collector consumed only by a writer is no longer read as feeding one.');
    }

    public function test_adding_a_consumer_that_reports_takes_the_mark_away(): void
    {
        $collector = $this->package(reportingConsumer: true);

        $this->assertFalse(AggregateRule::onlyFeedsAWriter($collector), 'A collector gained a consumer that builds a rule error, and the permanent mark did not lift.');
    }

    /** A minimal package tree: one collector, one writer consuming it, optionally one that reports. */
    private function package(bool $reportingConsumer): string
    {
        $this->root = sys_get_temp_dir() . '/permanent-refusal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Collectors', 0o777, true);
        mkdir($this->root . '/Rules', 0o777, true);

        $collector = $this->root . '/Collectors/ManifestCollector.php';
        file_put_contents($collector, "<?php\n\nfinal class ManifestCollector {}\n");

        file_put_contents(
            $this->root . '/Rules/WriteManifestRule.php',
            "<?php\n\nfinal class WriteManifestRule\n{\n"
            . "    public const COLLECTOR = ManifestCollector::class;\n\n"
            . "    public function processNode(): array { file_put_contents('m.json', ''); return []; }\n}\n",
        );

        if ($reportingConsumer) {
            file_put_contents(
                $this->root . '/Rules/ReportsManifestRule.php',
                "<?php\n\nfinal class ReportsManifestRule\n{\n"
                . "    public const COLLECTOR = ManifestCollector::class;\n\n"
                . "    public function processNode(): array { return [RuleErrorBuilder::message('x')->build()]; }\n}\n",
            );
        }

        return $collector;
    }
}
