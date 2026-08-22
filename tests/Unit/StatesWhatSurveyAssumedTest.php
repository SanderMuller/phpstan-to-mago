<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * Survey mode relaxes two checks on purpose, and says so.
 *
 * It assumes a hook exists for a node type that has none, and assumes a property resolves where no field
 * mapping does, so the report can say what a rule needs *in total* rather than stopping at its first
 * structural blocker. Both were silent, and a silent assumption is how a ranking goes wrong: measured over
 * 143 vendored rules, 25 named a different first obstacle under survey than an emit run does, and every one
 * of those was a body-level gap sitting behind a blocker the survey had walked past.
 *
 * That is not hypothetical. A handoff ranked `unknown local $this` as the cheapest thing to build because two
 * rules reached it; both are `phpstan/phpstan-deprecation-rules`, and both are blocked by `Expr_ConstFetch`
 * having no hook, which no amount of work on `$this` would move.
 */
final class StatesWhatSurveyAssumedTest extends TestCase
{
    private const string RULE = __DIR__ . '/../Fixtures/Rules/UnmappedNodeTypeRule.php';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    protected function tearDown(): void
    {
        Transpiler::$survey = false;
    }

    public function test_an_emit_run_refuses_on_the_missing_hook(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/^no hook mapping for node type PhpParser\\\\Node\\\\Expr\\\\ConstFetch$/');

        (new Transpiler(self::RULE))->transpile();
    }

    public function test_a_survey_run_reports_the_body_gap_and_the_assumption_behind_it(): void
    {
        Transpiler::$survey = true;

        $message = '';

        try {
            (new Transpiler(self::RULE))->transpile();
        } catch (Refusal $refusal) {
            $message = $refusal->getMessage();
        }

        // The body gap first, because that is the new information, and the assumption after it, because
        // without that the reader cannot tell the gap is not the only thing in the way.
        $this->assertStringContainsString('assuming a hook for PhpParser\Node\Expr\ConstFetch', $message);
        $this->assertStringNotContainsString('assuming a hook for', explode(', assuming', $message)[0]);
        $this->assertNotSame('', $message, 'The survey run did not refuse at all.');
    }
}
