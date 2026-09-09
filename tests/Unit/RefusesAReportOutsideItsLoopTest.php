<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A report that reads a name only its loop binds is refused, not emitted.
 *
 * The emitted shape everything here assumes is a chain of guards followed by one report. A rule whose
 * finding is built by an inlined helper *inside* a loop breaks it in two places at once: the helper's
 * trailing `return null` emits a bail because `loopDepth > 0` — right for a `return []` that really is an
 * exit — and the rule's own report is appended by the emitter after the loop has closed, reading the name
 * the loop bound.
 *
 * No syntactic check this project makes of its output catches it. The file parses, every `Support::` helper
 * it calls exists, and no Rust leaks into it. On the php target the escaped read carries its sigil, so it is
 * an undefined variable — a PHP 8 warning evaluating as `null`; on the Rust targets the same read is written
 * bare, where it is a constant lookup. Either way the plugin loads and reports under a name that is not
 * there, which is the plausible-but-wrong shape this project refuses rather than approximates.
 *
 * `mago analyze` does report it (`possibly-undefined-variable` and `unevaluated-code`), and running the
 * analyser over the emitted tree is a second net worth having — but it catches a plugin that was already
 * written, where this refuses to write one.
 *
 * No rule in the seven installed packages reaches it, so no snapshot could ever have covered it, which is
 * why the check has a fixture of its own. The pair varies one axis — where the report is built — and the
 * control has to keep emitting: a check that refused both would be refusing every rule that reports from
 * inside a loop, and the refusing row alone cannot tell those apart.
 */
final class RefusesAReportOutsideItsLoopTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/LoopReports/Rules';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_a_report_reading_a_name_only_its_loop_binds_is_refused(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/reports after a loop, reading `constant_string`/');

        (new Transpiler(self::RULES . '/ReportsAfterTheLoopRule.php'))->transpile();
    }

    public function test_the_same_rule_reporting_inside_the_loop_still_emits(): void
    {
        $emitted = (new Transpiler(self::RULES . '/ReportsInsideTheLoopRule.php'))->transpile()['rust'];

        // Inside the loop the binding is in scope, so the report is emitted where the rule builds it and the
        // name it reads is the one the `foreach` opened.
        $this->assertStringContainsString('foreach (', $emitted);
        $this->assertStringContainsString('$constant_string', $emitted);

        // And it is genuinely inside: the report is emitted before the loop's closing brace, which is the
        // difference the pair exists to measure rather than something a substring can assert on its own.
        $loop = strpos($emitted, 'foreach (');
        $report = strpos($emitted, '$context->report(');
        $this->assertIsInt($loop);
        $this->assertIsInt($report);
        $this->assertGreaterThan($loop, $report);
        $this->assertStringContainsString('fixture.reportsInsideTheLoop', $emitted);
    }
}
