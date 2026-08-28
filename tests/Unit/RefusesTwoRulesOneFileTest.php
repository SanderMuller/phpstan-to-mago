<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Cli;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * An output file is named for the rule's class short name, so two rules can claim one name.
 *
 * A package that names one class per namespace does this everywhere: `phpat/phpat` 0.12.0 has 25 names
 * claimed by 55 of its 61 rules. Every write would succeed and the last one would win, so the only trace
 * was the same name printed twice in the survey. The manifest is keyed the same way, which means the
 * corpus differential would credit a finding to whichever rule sorted last -- worse than losing a file,
 * because a wrong attribution is one you would trust.
 *
 * Nothing has been overwritten so far: the seven packages this repository installs collide zero times, and
 * every phpat rule refuses before emission. The guard exists because that is luck, not design.
 */
final class RefusesTwoRulesOneFileTest extends TestCase
{
    private const string PACKAGE = __DIR__ . '/../Fixtures/CollidingPackage';

    private const string ALONE = self::PACKAGE . '/ShouldBeUppercase/NamedConstantRule.php';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_refuses_both_rules_that_claim_one_output_name(): void
    {
        $output = $this->cli(['--survey', self::PACKAGE], expected: 1);

        $this->assertSame(2, substr_count($output, 'two rules would be written to NamedConstantRule.php'), $output);
        $this->assertStringContainsString('emitted: 0, refused: 2', $output);
    }

    /**
     * Both paths, because the collision belongs to the pair rather than to whichever file sorts last.
     */
    public function test_names_both_files_in_the_refusal(): void
    {
        $output = $this->cli(['--survey', self::PACKAGE], expected: 1);

        $this->assertStringContainsString('ShouldBeUppercase/NamedConstantRule.php', $output);
        $this->assertStringContainsString('ShouldNotBeUppercase/NamedConstantRule.php', $output);
    }

    /**
     * The pair is what refuses, not the rule. Either one alone emits, so the guard is measured against a
     * rule the vocabulary covers rather than one that would have refused anyway.
     */
    public function test_either_rule_emits_when_it_does_not_share_the_name(): void
    {
        $output = $this->cli(['--survey', self::ALONE]);

        $this->assertStringContainsString('EMIT    NamedConstantRule', $output);
        $this->assertStringContainsString('emitted: 1, refused: 0', $output);
    }

    /**
     * Checked in survey mode too, because a survey that counts a rule the emitting run refuses is exactly
     * the disagreement the target banner exists to prevent.
     */
    public function test_the_emitting_run_refuses_the_pair_as_the_survey_says(): void
    {
        $output = $this->cli([self::PACKAGE], expected: 1);

        $this->assertStringContainsString('emitted: 0, refused: 2', $output);
    }

    /**
     * @param list<string> $argv
     */
    private function cli(array $argv, int $expected = 0): string
    {
        ob_start();
        $status = Cli::run($argv, sys_get_temp_dir() . '/phpstan-to-mago-collision-test-' . getmypid());
        $output = (string) ob_get_clean();

        $this->assertSame($expected, $status, $output);

        return $output;
    }
}
