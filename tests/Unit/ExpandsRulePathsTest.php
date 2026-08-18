<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\RulePaths;

/**
 * Pointing the tool at a rule package is the shape dogfooding needs, and a package is mostly not rules.
 * Surveying `hihaho/phpstan-rules` before this existed meant shell-globbing for `*Rule.php`, which both
 * missed two rules named otherwise and picked up an abstract base that can never be one.
 */
final class ExpandsRulePathsTest extends TestCase
{
    private const string PACKAGE = __DIR__ . '/../Fixtures/RulePackage';

    public function test_walks_a_directory_for_rules_only(): void
    {
        $files = RulePaths::expand([self::PACKAGE]);

        $this->assertSame(['ConcreteRule.php'], array_map(basename(...), $files));
    }

    public function test_takes_a_named_file_as_given_even_when_it_is_not_a_rule(): void
    {
        $trait = self::PACKAGE . '/Traits/DetectsSomething.php';

        $this->assertSame([$trait], RulePaths::expand([$trait]));
    }

    public function test_is_stable_in_order_across_runs(): void
    {
        $this->assertSame(RulePaths::expand([self::PACKAGE]), RulePaths::expand([self::PACKAGE]));
    }
}
