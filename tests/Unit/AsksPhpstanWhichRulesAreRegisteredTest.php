<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\RegisteredRules;
use Sandermuller\PhpstanToMago\RulePaths;
use Sandermuller\PhpstanToMago\Tests\Fixtures\RegisteredRulePackage\ConfiguredByTheProjectRule;
use Sandermuller\PhpstanToMago\Tests\Fixtures\RegisteredRulePackage\DiscoveredRule;
use Sandermuller\PhpstanToMago\Transpiler;

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

    /**
     * A rule no package neon wires reads its values from the project that registered it.
     *
     * `PackageConfiguration` reads the package's own neon on purpose, so a generated plugin stands alone.
     * For a rule the package registers nowhere there is nothing there to read, and the refusal used to say
     * so — correctly, and permanently, because no package change would ever wire it. The consumer is where
     * those values live, and the container this run already asks which rules to carry across is holding the
     * constructed rule with them in it.
     */
    public function test_reads_the_values_the_project_built_a_rule_with(): void
    {
        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        $arguments = $registered->argumentsFor(ConfiguredByTheProjectRule::class);

        // A promoted parameter, held by a property of the same name.
        $this->assertSame(['dump', 'dd'], $arguments['banned'] ?? null);

        // And one that is not promoted, so nothing holds the parameter and only what the constructor
        // derived from it can be read. Lower-cased by that derivation, which is the evidence it is the
        // computed table rather than the argument.
        $this->assertSame(['vardump' => true, 'ray' => true], $arguments['bannedLookup'] ?? null);
    }

    /**
     * And the emitted plugin carries them, keys and all.
     *
     * The map is the half that fails quietly. A lookup table rendered without its keys — `[true, true]` for
     * `['vardump' => true, 'ray' => true]` — is still valid PHP, still loads, and answers false to every
     * membership test it exists to answer. Every default before this one came from a package's
     * `parameters:` and was a list, so the renderer had never been handed a keyed array.
     */
    public function test_the_emitted_plugin_carries_what_the_project_configured(): void
    {
        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        $target = Transpiler::$target;
        Transpiler::$consumerConfiguration = $registered;
        Transpiler::$target = 'php';

        try {
            $emitted = (new Transpiler((string) (new ReflectionClass(ConfiguredByTheProjectRule::class))->getFileName()))
                ->transpile()['rust'];
        } finally {
            Transpiler::$consumerConfiguration = null;
            Transpiler::$target = $target;
        }

        $this->assertStringContainsString("\$banned = ['dump', 'dd']", $emitted);
        $this->assertStringContainsString("\$bannedLookup = ['vardump' => true, 'ray' => true]", $emitted);
    }

    /**
     * A rule that takes nothing carryable is the same answer as a rule this project does not register.
     *
     * Both mean "no consumer value for this property". Asserted so that a future change cannot start
     * answering null for one of them and leave callers to tell two absences apart.
     */
    public function test_a_rule_with_no_configured_values_reads_as_empty(): void
    {
        $registered = RegisteredRules::discover(self::PROJECT, self::PHPSTAN);

        $this->assertSame([], $registered->argumentsFor(DiscoveredRule::class));
        $this->assertSame([], $registered->argumentsFor('Nothing\\Registers\\This'));
    }

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
