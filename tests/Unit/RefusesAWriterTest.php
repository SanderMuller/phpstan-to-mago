<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A rule that writes a file instead of reporting, and the collector that feeds it.
 *
 * `hihaho/phpstan-rules` ships such a pair: `WriteNamedArgumentManifestRule` builds a JSON manifest with
 * `file_put_contents` and returns `[]` always. It is a build artefact wearing a rule's interface, and
 * `report()` is an analyzer plugin's only output — so there is nothing for a plugin to do, and "agreement"
 * has no meaning for a file on disk.
 *
 * Both halves matter. Before this, the collector refused on whichever construct its body happened to trip on
 * first, which read as a vocabulary gap and invited someone to close it. Nothing about the collector's body
 * would have helped: its only consumer can never be a plugin.
 */
final class RefusesAWriterTest extends TestCase
{
    private const string PACKAGE = __DIR__ . '/../Fixtures/WriterPackage/src';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_a_rule_that_reports_nothing_is_refused_for_that_reason(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/reports nothing: it writes a file/');

        (new Transpiler(self::PACKAGE . '/Rules/WriteManifestRule.php'))->transpile();
    }

    public function test_a_collector_whose_only_consumer_writes_is_refused_for_that_reason(): void
    {
        // Traced by scanning the package for a rule naming this collector, not inferred from its name — the
        // same discipline the rest of the transpiler holds to.
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/every rule that consumes this collector reports nothing/');

        (new Transpiler(self::PACKAGE . '/Collectors/ManifestCollector.php'))->transpile();
    }

    public function test_a_collector_feeding_a_reporting_rule_is_not_refused_for_writing(): void
    {
        // The check has to be about what the consumer does, not about being a collector. type-coverage's
        // collectors feed rules that report, so they must not be caught by it.
        $message = '';

        try {
            (new Transpiler(
                dirname(__DIR__, 2) . '/vendor/tomasvotruba/type-coverage/src/Collectors/ParamTypeDeclarationCollector.php',
            ))->transpile();
        } catch (Refusal $refusal) {
            $message = $refusal->getMessage();
        }

        // Either it emits or it refuses for something else. What it must not say is that it feeds a writer.
        $this->assertStringNotContainsString('reports nothing', $message);
        $this->assertStringNotContainsString('every rule that consumes this collector', $message);
    }
}
