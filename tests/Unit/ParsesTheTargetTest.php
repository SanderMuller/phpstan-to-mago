<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sandermuller\PhpstanToMago\Cli;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * The target is part of every count this tool prints.
 *
 * A rule can render as Rust and be refused as PHP, so surveying one target while emitting another
 * disagrees for a legitimate reason and reads as a bug in the survey. That happened: a bare `--survey`
 * reported 4 emitted where a PHP run emitted 3, and the difference was entirely the target.
 */
final class ParsesTheTargetTest extends TestCase
{
    private const string RULE = __DIR__ . '/../Fixtures/Rules/UppercaseConstantRule.php';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_the_default_target_is_the_php_product(): void
    {
        // Read the declared default rather than the current value: `Transpiler::$target` is static, so by
        // the time a test observes it another test may have set it. Only the declaration is the contract.
        $declared = (new ReflectionProperty(Transpiler::class, 'target'))->getDefaultValue();

        $this->assertSame('php', $declared);
    }

    public function test_names_the_target_it_was_given_with_no_flag(): void
    {
        $this->assertStringContainsString('(target: php)', $this->cli(['--survey', self::RULE]));
    }

    public function test_selects_a_named_target(): void
    {
        $this->assertStringContainsString('(target: analyzer)', $this->cli(['--survey', '--target=analyzer', self::RULE]));
        $this->assertSame('analyzer', Transpiler::$target);
    }

    public function test_refuses_an_unknown_target_rather_than_falling_back(): void
    {
        $output = $this->cli(['--survey', '--target=rust', self::RULE], expected: 1);

        $this->assertStringContainsString('unknown target "rust"', $output);
    }

    public function test_names_the_target_alongside_every_count(): void
    {
        $this->assertMatchesRegularExpression('/emitted: \d+, refused: \d+ \(target: \w+\)/', $this->cli(['--survey', self::RULE]));
    }

    /**
     * @param list<string> $argv
     */
    private function cli(array $argv, int $expected = 0): string
    {
        ob_start();
        $status = Cli::run($argv, sys_get_temp_dir() . '/phpstan-to-mago-target-test');
        $output = (string) ob_get_clean();

        $this->assertSame($expected, $status, $output);

        return $output;
    }
}
