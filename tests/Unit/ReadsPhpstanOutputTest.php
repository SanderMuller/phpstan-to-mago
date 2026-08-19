<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\PhpstanReport;

/**
 * The differential's PHPStan side.
 *
 * This is test infrastructure, and it is tested because a silent failure here weakens every rule's
 * comparison at once. The rule that matters: output this does not recognise **throws**. An earlier version
 * returned an empty result instead, so a PHPStan run that never started read as "found nothing" — and the
 * comparison then passed for five rules that reported nothing either. Two tools agreeing on zero is equally
 * consistent with clean code and with neither tool looking.
 */
final class ReadsPhpstanOutputTest extends TestCase
{
    private const string IDENTIFIER = 'fixture.someRule';

    public function test_reads_phpstans_own_json_shape(): void
    {
        $output = json_encode([
            'totals' => ['file_errors' => 2, 'errors' => 0],
            'files' => [
                '/project/src/Bad.php' => ['errors' => 2, 'messages' => [
                    ['line' => 13, 'message' => 'Do not do that', 'identifier' => self::IDENTIFIER],
                    ['line' => 9, 'message' => 'Do not do that', 'identifier' => self::IDENTIFIER],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['Bad.php' => ['13: Do not do that', '9: Do not do that']],
            PhpstanReport::findings($output, self::IDENTIFIER),
        );
    }

    public function test_reads_a_wrapped_reporting_envelope(): void
    {
        $output = json_encode([
            'tool' => 'phpstan',
            'result' => 'failed',
            'errors' => 1,
            'error_details' => [
                '/project/src/Bad.php' => [
                    ['line' => 7, 'message' => 'Do not do that', 'identifier' => self::IDENTIFIER],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['Bad.php' => ['7: Do not do that']],
            PhpstanReport::findings($output, self::IDENTIFIER),
        );
    }

    public function test_keeps_only_the_rule_being_compared(): void
    {
        $output = json_encode([
            'files' => [
                '/project/src/Bad.php' => ['messages' => [
                    ['line' => 13, 'message' => 'Do not do that', 'identifier' => self::IDENTIFIER],
                    ['line' => 4, 'message' => 'Class not found', 'identifier' => 'class.notFound'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['Bad.php' => ['13: Do not do that']],
            PhpstanReport::findings($output, self::IDENTIFIER),
            "PHPStan's own diagnostics are not the rule's findings, and counting them would compare two "
            . 'different things.',
        );
    }

    public function test_a_clean_run_reports_nothing(): void
    {
        $this->assertSame(
            [],
            PhpstanReport::findings(json_encode(['tool' => 'phpstan', 'result' => 'passed'], JSON_THROW_ON_ERROR), self::IDENTIFIER),
        );

        $this->assertSame(
            [],
            PhpstanReport::findings(json_encode(['totals' => ['file_errors' => 0]], JSON_THROW_ON_ERROR), self::IDENTIFIER),
        );
    }

    public function test_output_that_is_not_json_throws_rather_than_reading_as_zero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('produced no JSON');

        PhpstanReport::findings('PHP Fatal error: something went wrong', self::IDENTIFIER);
    }

    public function test_a_run_that_failed_to_start_throws_rather_than_reading_as_zero(): void
    {
        // The exact shape PHPStan emits when a rule class cannot be loaded: valid JSON, no findings key.
        $output = json_encode([
            'tool' => 'phpstan',
            'raw' => ['In Resolver.php line 114:', "Service (Stub::__construct()): Class 'Stub' not found."],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not run');

        PhpstanReport::findings($output, self::IDENTIFIER, 'SomeRule');
    }

    public function test_findings_are_ordered_so_a_comparison_is_stable(): void
    {
        $output = json_encode([
            'files' => [
                '/project/src/Good.php' => ['messages' => [['line' => 2, 'message' => 'b', 'identifier' => self::IDENTIFIER]]],
                '/project/src/Bad.php' => ['messages' => [
                    ['line' => 30, 'message' => 'a', 'identifier' => self::IDENTIFIER],
                    ['line' => 4, 'message' => 'a', 'identifier' => self::IDENTIFIER],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $findings = PhpstanReport::findings($output, self::IDENTIFIER);

        $this->assertSame(['Bad.php', 'Good.php'], array_keys($findings));
        // Sorted as strings, which is what the other side is sorted by too, so the two are comparable.
        $this->assertSame(['30: a', '4: a'], $findings['Bad.php']);
    }
}
