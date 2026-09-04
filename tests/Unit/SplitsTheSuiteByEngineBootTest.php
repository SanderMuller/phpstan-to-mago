<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The default suite boots no engine, and the boundary is asserted rather than remembered.
 *
 * `composer test` excludes `#[Group('engine')]` and runs in about 20 seconds; `composer test-full` runs
 * everything and takes minutes, because 17 classes start a real `mago` or a real PHPStan. That split is only
 * worth having if it stays true, and an attribute is easy to forget on a new test — which is exactly how a
 * slow check creeps back into the fast suite, or worse, how an engine check silently stops running anywhere.
 *
 * **The list below was measured, not recalled.** Grepping for what boots a subprocess found 17 classes where
 * a reading of the `phpstan.neon.dist` `proc_open` exemptions would have named 8: nine more reach an engine
 * through a support class — `FiresGate`, `CorpusDifferential`, `RegisteredRules` and the coverage corpora —
 * rather than calling `proc_open` themselves. A boundary drawn from the exemption list would have left those
 * nine in the fast suite, which is the failure this test exists to prevent.
 */
#[CoversNothing]
final class SplitsTheSuiteByEngineBootTest extends TestCase
{
    /** What a test reaches an engine through, directly or by a support class that does. */
    private const array BOOTS_AN_ENGINE = [
        'proc_open',
        'Subprocess::',
        'FiresGate',
        'CorpusDifferential',
        'CoverageCorpus',
        'CoverageControl',
        'CoverageSetDiff',
        'HierarchyKnowledge',
        'TypeDescriptions',
        'RegisteredRules',
    ];

    public function test_every_test_that_boots_an_engine_is_grouped(): void
    {
        $ungrouped = [];
        foreach ($this->unitTestFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (! $this->bootsAnEngine($source) || str_contains($source, "#[Group('engine')]")) {
                continue;
            }

            $ungrouped[] = basename($file, '.php');
        }

        sort($ungrouped);

        $this->assertSame(
            [],
            $ungrouped,
            'These tests reach a real engine and are not in the `engine` group, so `composer test` starts '
            . "subprocesses it promises not to:\n  " . implode("\n  ", $ungrouped),
        );
    }

    /**
     * And the inverse, because a group that grows is a fast suite that quietly stops being fast in reverse:
     * a test marked `engine` that boots nothing has been moved out of the default run for no reason, and
     * nothing else would ever say so.
     */
    public function test_no_test_is_grouped_without_booting_an_engine(): void
    {
        $misgrouped = [];
        foreach ($this->unitTestFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, "#[Group('engine')]") && ! $this->bootsAnEngine($source)) {
                $misgrouped[] = basename($file, '.php');
            }
        }

        sort($misgrouped);

        $this->assertSame(
            [],
            $misgrouped,
            'These tests are in the `engine` group and boot nothing, so they left the default suite for no '
            . "reason:\n  " . implode("\n  ", $misgrouped),
        );
    }

    /** Both scripts exist, because a suite nobody can run is a suite nobody runs. */
    public function test_composer_declares_both_suites(): void
    {
        /** @var array{scripts?: array<string, string|list<string>>} $composer */
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $scripts = $composer['scripts'] ?? [];

        $this->assertSame('vendor/bin/phpunit --exclude-group engine', $scripts['test'] ?? null);
        $this->assertSame('vendor/bin/phpunit', $scripts['test-full'] ?? null);

        // The gate before a commit has to be the full one. Splitting the suite moved the fires gate out of
        // `test`, and that gate is this project's answer to "'It emitted' is not a result" — a bare
        // snake_case identifier is valid PHP, so only running the plugin catches it.
        $this->assertContains('@test-full', (array) ($scripts['qa-check'] ?? []));
    }

    /** @return list<string> */
    private function unitTestFiles(): array
    {
        $files = glob(__DIR__ . '/*.php');

        return $files === false ? [] : array_values(array_filter(
            $files,
            static fn (string $file): bool => basename($file) !== 'SplitsTheSuiteByEngineBootTest.php',
        ));
    }

    private function bootsAnEngine(string $source): bool
    {
        foreach (self::BOOTS_AN_ENGINE as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }
}
