<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\PackageConfiguration;

/**
 * Reading a rule package's own configuration.
 *
 * The split this makes is the load-bearing part: `services: arguments:` spells a configured value `%path%`
 * and a PHPStan service `@name`, and only the first can become a constructor parameter on a generated
 * plugin. Getting it wrong in either direction is bad in a different way — treating a service as
 * configuration emits a plugin asking for something no worker can supply, and treating configuration as a
 * service refuses a rule that is perfectly portable.
 *
 * Both a purpose-built fixture package and the real vendored packages are used. The fixture pins the edge
 * cases; the vendored ones prove the reader survives configuration nobody wrote for it.
 */
final class ReadsPackageConfigurationTest extends TestCase
{
    private const string CONFIGURED = __DIR__ . '/../Fixtures/ConfiguredPackage/src/Rules/ConfiguredRule.php';

    private const string UNCONFIGURED = __DIR__ . '/../Fixtures/UnconfiguredPackage/src/Rules/SomeRule.php';

    public function test_finds_the_neon_a_package_names_in_its_composer_manifest(): void
    {
        $this->assertInstanceOf(PackageConfiguration::class, PackageConfiguration::forRuleFile(self::CONFIGURED));
    }

    public function test_a_package_that_declares_no_neon_has_no_configuration(): void
    {
        $this->assertNotInstanceOf(
            PackageConfiguration::class,
            PackageConfiguration::forRuleFile(self::UNCONFIGURED),
            'A package with no `extra.phpstan.includes` has nothing to read, which is a real answer rather '
            . 'than a failure — a rule with no configured arguments needs none of it.',
        );
    }

    public function test_separates_a_configured_value_from_a_phpstan_service(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(
            [
                ['name' => 'namespaces', 'kind' => 'config', 'reference' => 'fixture.namespaces'],
                ['name' => 'limit', 'kind' => 'config', 'reference' => 'fixture.limit'],
                ['name' => 'reflectionProvider', 'kind' => 'service', 'reference' => 'reflectionProvider'],
            ],
            $configuration->argumentsFor('Fixture\\Rules\\ConfiguredRule'),
        );
    }

    public function test_reads_a_default_through_its_dotted_path(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(['App', 'Tests'], $configuration->defaultFor('fixture.namespaces'));
        $this->assertTrue($configuration->hasParameter('fixture.namespaces'));
    }

    public function test_a_parameter_that_does_not_exist_is_distinguishable_from_one_defaulting_to_null(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertNull($configuration->defaultFor('fixture.missing'));
        $this->assertFalse(
            $configuration->hasParameter('fixture.missing'),
            'Without this the generated constructor cannot tell "defaults to null" from "no such parameter", '
            . 'and the second has to be refused rather than defaulted.',
        );
    }

    public function test_an_outer_file_wins_over_the_one_it_includes(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        // nested.neon sets `limit: 1`; the file that includes it sets `limit: 3`.
        $this->assertSame(3, $configuration->defaultFor('fixture.limit'));
        // ...and a parameter only the nested file declares still comes through.
        $this->assertTrue($configuration->defaultFor('fixture.deep'));
    }

    public function test_reads_a_rule_wired_only_in_an_included_file(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(
            [['name' => 'deep', 'kind' => 'config', 'reference' => 'fixture.deep']],
            $configuration->argumentsFor('Fixture\Rules\NestedOnlyRule'),
        );
    }

    public function test_a_literal_argument_counts_as_configuration_with_no_parameter_behind_it(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(
            [['name' => 'suffix', 'kind' => 'config', 'reference' => 'Repository']],
            $configuration->argumentsFor('Fixture\Rules\LiteralArgumentRule'),
            'A wired literal is configuration the package fixed itself, so the generated constructor can '
            . 'carry it as its own default.',
        );
    }

    public function test_a_rule_the_package_does_not_wire_has_no_arguments(): void
    {
        $configuration = PackageConfiguration::forRuleFile(self::CONFIGURED);
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame([], $configuration->argumentsFor('Fixture\Rules\UnwiredRule'));
    }

    public function test_reads_the_real_hihaho_style_wiring_of_a_vendored_package(): void
    {
        // type-coverage builds a `Configuration` value object from one whole parameter array, which is a
        // different shape from a rule taking its values directly, and both have to come out classified.
        $configuration = PackageConfiguration::forRuleFile(
            __DIR__ . '/../../vendor/tomasvotruba/type-coverage/src/Rules/ParamTypeCoverageRule.php',
        );
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(
            [['name' => '0', 'kind' => 'config', 'reference' => 'type_coverage']],
            $configuration->argumentsFor('TomasVotruba\\TypeCoverage\\Configuration'),
        );
        $this->assertSame(99, $configuration->defaultFor('type_coverage.param_type'));
    }

    public function test_reads_the_defaults_of_the_cognitive_complexity_package(): void
    {
        $configuration = PackageConfiguration::forRuleFile(
            __DIR__ . '/../../vendor/tomasvotruba/cognitive-complexity/src/Rules/ClassLikeCognitiveComplexityRule.php',
        );
        $this->assertInstanceOf(PackageConfiguration::class, $configuration);

        $this->assertSame(9, $configuration->defaultFor('cognitive_complexity.function'));
        $this->assertSame(40, $configuration->defaultFor('cognitive_complexity.class'));
        $this->assertSame([], $configuration->defaultFor('cognitive_complexity.dependency_tree_types'));
    }
}
