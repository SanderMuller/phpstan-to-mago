<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\RegisteredRules;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Tests\Fixtures\RegisteredRulePackage\DiscoveredRule;

/**
 * A coverage figure is only worth reading if its denominator is the rules a project actually runs.
 *
 * Surveying `hihaho/phpstan-rules` by walking its directory reported eight of twenty; the project registers
 * eight, of which this tool carries four. The first number described the package and read as much worse than
 * the truth. It is just as easy to go the other way: a rule pulled in by an `includes:` two files deep, or by
 * `extension-installer`, never appears in the project's own config, so a scan that misses it reports full
 * coverage of a set that was never complete.
 *
 * These tests run PHPStan for real, which is slower than parsing files, and that is the point: level
 * resolution, includes and extension discovery are PHPStan's own and reimplementing them is how the answer
 * goes quietly wrong.
 */
final class AsksPhpstanWhichRulesAreRegisteredTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/../Fixtures/RegisteredProject';

    private const string PHPSTAN = __DIR__ . '/../../vendor/bin/phpstan';

    public function test_finds_a_rule_that_walking_the_project_cannot_see(): void
    {
        $this->assertSame([], RulePaths::expand([self::PROJECT]), 'the fixture rule must live outside the project for this to mean anything');

        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        $this->assertContains(
            (string) (new ReflectionClass(DiscoveredRule::class))->getFileName(),
            $registered->portableFiles(),
        );
    }

    public function test_sets_phpstan_s_own_rules_apart_from_the_project_s(): void
    {
        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        // PHPStan at any level registers rules of its own, and Mago implements its own equivalents, so
        // carrying them across would produce two of each. Zero here would mean the split silently failed.
        $this->assertGreaterThan(0, $registered->coreCount());

        $own = array_values(array_filter(
            $registered->rules,
            static fn (array $rule): bool => $rule['class'] === DiscoveredRule::class,
        ));

        $this->assertCount(1, $own);
        $this->assertFalse($own[0]['core']);

        // The split has to reach the files the caller transpiles, not just the classification. Without this
        // the same run would try to carry across all of PHPStan's own rules, and every count after it would
        // describe that instead of the project.
        $portable = [];
        foreach ($registered->rules as $rule) {
            if (! $rule['core'] && $rule['file'] !== null) {
                $portable[$rule['file']] = true;
            }
        }

        $portable = array_keys($portable);
        sort($portable);

        $this->assertSame($portable, $registered->portableFiles());
    }

    public function test_counts_a_class_registered_twice_as_two_services(): void
    {
        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        // The fixture registers it through `rules:` and again as a service of its own. Two services are two
        // configurations, and a generated plugin carries one, so the count has to survive to the caller.
        $this->assertSame(2, $registered->duplicated()[DiscoveredRule::class] ?? null);

        $file = (string) (new ReflectionClass(DiscoveredRule::class))->getFileName();

        $this->assertSame([$file], array_values(array_filter(
            $registered->portableFiles(),
            static fn (string $candidate): bool => $candidate === $file,
        )));
    }

    public function test_refuses_a_directory_with_no_phpstan_configuration(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/no phpstan\.neon/');

        RegisteredRules::discover(__DIR__ . '/../Fixtures/RegisteredRulePackage', self::PHPSTAN);
    }

    public function test_refuses_a_path_that_is_not_there(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/no such path/');

        RegisteredRules::discover(__DIR__ . '/../Fixtures/NoSuchProject', self::PHPSTAN);
    }

    public function test_refuses_a_run_that_failed_to_start_rather_than_calling_it_empty(): void
    {
        // The failure this exists for: PHPStan not starting produces no rules, and no rules is also what a
        // project with none of its own produces. Read as the second, a broken config reports full coverage
        // of an empty set. So the reason PHPStan gave has to survive into the refusal.
        try {
            RegisteredRules::discover(__DIR__ . '/../Fixtures/BrokenProject', self::PHPSTAN);

            self::fail('a configuration PHPStan cannot load must not read as a project with no rules');
        } catch (Refusal $refusal) {
            $this->assertStringContainsString('did not report its registered rules', $refusal->getMessage());
            $this->assertStringContainsString('a-file-that-is-not-there.neon', $refusal->getMessage());
        }
    }

    public function test_refuses_a_project_with_no_phpstan_to_ask(): void
    {
        // Rather than reporting a project with no rules, which is what an unchecked run of a missing binary
        // would look like from the outside.
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/no PHPStan to ask/');

        RegisteredRules::discover(self::PROJECT, self::PROJECT . '/there-is-no-phpstan-here');
    }
}
