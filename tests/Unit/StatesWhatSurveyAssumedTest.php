<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Cli;
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
 * rules reached it; both are `phpstan/phpstan-deprecation-rules`, and both were blocked by `Expr_ConstFetch`
 * having no hook, which no amount of work on `$this` would have moved. That hook exists now and one of the
 * two emits, which is the ranking being wrong rather than early: the work that moved them was the hook.
 *
 * The fixture followed the vocabulary to `Stmt\Expression`, and then to `Stmt\Label` when that gained a hook too.
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

    /**
     * The survey says what its refusal is the scope of, next to the count rather than in a document.
     *
     * This class's own docblock records a handoff that ranked work from first obstacles and ranked it wrong,
     * and the census header has carried the same warning in bold for longer than that. Both were read past,
     * because a warning thirty lines above the data cannot reach someone who greps — so the line is printed
     * where the refusal is, by the thing that produced it.
     *
     * Asserted rather than left as prose: a line nothing checks is one a refactor drops, which is the whole
     * difference between this and the header that did not work.
     */
    public function test_a_survey_says_a_refusal_is_only_the_first_obstacle(): void
    {
        Transpiler::$survey = true;

        ob_start();
        $status = Cli::run(['--survey', self::RULE], sys_get_temp_dir() . '/phpstan-to-mago-survey-scope-' . getmypid());
        $output = (string) ob_get_clean();

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('REFUSE', $output);
        $this->assertStringContainsString('first obstacle only', $output);
        $this->assertStringContainsString('needs-at-least:', $output);
    }

    /**
     * An emit run says what an emit is the scope of, for the same reason the survey does.
     *
     * Found by sweeping for the pattern rather than by hitting the defect: the target-and-count pair already
     * carried its configuration, the survey line was added when a refusal had been misread, and the emit
     * count was the third instance of the same shape sitting untreated three lines away. After fixing one,
     * look for it in adjacent code.
     */
    public function test_an_emit_run_says_an_emit_is_not_a_result(): void
    {
        ob_start();
        $status = Cli::run(
            [__DIR__ . '/../Fixtures/Rules/EveryExpressionRule.php'],
            sys_get_temp_dir() . '/phpstan-to-mago-emit-scope-' . getmypid(),
        );
        $output = (string) ob_get_clean();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('emitted: 1', $output);
        $this->assertStringContainsString('not that the plugin loads or reports', $output);
        $this->assertStringContainsString('fires gate', $output);
    }

    public function test_an_emit_run_refuses_on_the_missing_hook(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/^no hook mapping for node type PhpParser\\\\Node\\\\Stmt\\\\Label$/');

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
        $this->assertStringContainsString('assuming a hook for PhpParser\Node\Stmt\Label', $message);
        $this->assertStringNotContainsString('assuming a hook for', explode(', assuming', $message)[0]);
        $this->assertNotSame('', $message, 'The survey run did not refuse at all.');
    }
}
