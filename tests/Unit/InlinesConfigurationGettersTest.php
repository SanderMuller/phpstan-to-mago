<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\ConfigurationObject;

/**
 * Reducing a configuration value object to the parameter each getter reads.
 *
 * Asserted against the two real packages rather than a fixture, because the point is that their getters are
 * simple enough to inline — a claim about *their* code, which a fixture of my own could not make. Both are
 * vendored, so this is stable input.
 *
 * The refusals matter as much as the reads. A getter that derives rather than reads returns nothing here, so
 * the caller refuses instead of carrying it as a plain parameter read that would quietly answer something
 * else.
 */
final class InlinesConfigurationGettersTest extends TestCase
{
    private const string TYPE_COVERAGE = __DIR__ . '/../../vendor/tomasvotruba/type-coverage/src/Configuration.php';

    private const string COGNITIVE = __DIR__ . '/../../vendor/tomasvotruba/cognitive-complexity/src/Configuration.php';

    private const string DERIVING = __DIR__ . '/../Fixtures/Configurations/DerivingConfiguration.php';

    public function test_a_plain_read_inlines_to_its_parameter(): void
    {
        $configuration = ConfigurationObject::fromFile(self::COGNITIVE, 'cognitive_complexity');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        // `return $this->parameters['class'];`
        $this->assertSame(
            ['cognitive_complexity.class'],
            $configuration->pathsFor('getMaxClassCognitiveComplexity'),
        );
    }

    public function test_an_alias_fallback_keeps_both_keys_in_order(): void
    {
        $configuration = ConfigurationObject::fromFile(self::TYPE_COVERAGE, 'type_coverage');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        // `return $this->parameters['param'] ?? $this->parameters['param_type'];` — the caller takes the
        // first key the package actually declares, and only the second has a default.
        $this->assertSame(
            ['type_coverage.param', 'type_coverage.param_type'],
            $configuration->pathsFor('getRequiredParamTypeLevel'),
        );
    }

    public function test_a_fallback_to_a_literal_is_still_one_parameter(): void
    {
        $configuration = ConfigurationObject::fromFile(self::COGNITIVE, 'cognitive_complexity');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        // `return $this->parameters['dependency_tree_types'] ?? [];` — the package's own default covers the
        // fallback, so the key alone is the answer.
        $this->assertSame(
            ['cognitive_complexity.dependency_tree_types'],
            $configuration->pathsFor('getDependencyTreeTypes'),
        );
    }

    public function test_a_getter_that_derives_rather_than_reads_is_not_inlined(): void
    {
        $coverage = ConfigurationObject::fromFile(self::TYPE_COVERAGE, 'type_coverage');
        $cognitive = ConfigurationObject::fromFile(self::COGNITIVE, 'cognitive_complexity');
        $this->assertInstanceOf(ConfigurationObject::class, $coverage);
        $this->assertInstanceOf(ConfigurationObject::class, $cognitive);

        // `isConstantTypeCoverageEnabled()` guards on `PHP_VERSION_ID` then compares a level to zero, and
        // `isDependencyTreeEnabled()` tests another getter's result against `[]`. Neither is a parameter
        // read, so neither is claimed as one.
        $this->assertSame([], $coverage->pathsFor('isConstantTypeCoverageEnabled'));
        $this->assertSame([], $cognitive->pathsFor('isDependencyTreeEnabled'));
    }

    public function test_an_emptiness_test_names_the_getter_it_asks_about(): void
    {
        $configuration = ConfigurationObject::fromFile(self::COGNITIVE, 'cognitive_complexity');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        // `return $this->getDependencyTreeTypes() !== [];` — no parameter of its own, but the parameter
        // behind it is carried, so the comparison is emitted rather than the getter refused.
        $this->assertSame(
            ['getter' => 'getDependencyTreeTypes', 'expects' => 'non-empty'],
            $configuration->emptinessFor('isDependencyTreeEnabled'),
        );
    }

    public function test_a_getter_that_does_more_than_test_emptiness_is_not_claimed(): void
    {
        $coverage = ConfigurationObject::fromFile(self::TYPE_COVERAGE, 'type_coverage');
        $cognitive = ConfigurationObject::fromFile(self::COGNITIVE, 'cognitive_complexity');
        $this->assertInstanceOf(ConfigurationObject::class, $coverage);
        $this->assertInstanceOf(ConfigurationObject::class, $cognitive);

        // `isConstantTypeCoverageEnabled()` guards on `PHP_VERSION_ID` before comparing a level to zero, so
        // it is neither a parameter read nor an emptiness test, and a plain read is what a plain read is.
        $this->assertNull($coverage->emptinessFor('isConstantTypeCoverageEnabled'));
        $this->assertNull($cognitive->emptinessFor('getMaxClassCognitiveComplexity'));
        $this->assertNull($cognitive->emptinessFor('getSomethingNobodyWrote'));
    }

    public function test_only_an_identical_test_against_an_empty_literal_counts(): void
    {
        $configuration = ConfigurationObject::fromFile(self::DERIVING, 'fixture');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        // Each of these is one condition away from the recognised shape, and each asks a different question.
        // Carrying any of them as "is that parameter empty" would answer the wrong one.
        $this->assertNull($configuration->emptinessFor('isDefaultSet'), 'a populated literal is not emptiness');
        $this->assertNull($configuration->emptinessFor('isLooselyEmpty'), '`==` is not `===`');
        $this->assertNull($configuration->emptinessFor('isEmptyList'), 'the subject is a parameter, not a getter');
        $this->assertNull($configuration->emptinessFor('isKindSet'), 'an argument decides which key is read');
        $this->assertNull($configuration->emptinessFor('isOtherSet'), 'another object holds other parameters');

        // The polarity is what the emitted comparison reads off, so both directions are pinned.
        $this->assertSame(
            ['getter' => 'getTypes', 'expects' => 'empty'],
            $configuration->emptinessFor('isEmpty'),
        );

        // Recognised, and the parameter behind it is what the caller then has to find declared.
        $this->assertSame(
            ['getter' => 'getUndeclared', 'expects' => 'non-empty'],
            $configuration->emptinessFor('isUndeclaredSet'),
        );
    }

    public function test_a_getter_nobody_declared_reads_nothing(): void
    {
        $configuration = ConfigurationObject::fromFile(self::TYPE_COVERAGE, 'type_coverage');
        $this->assertInstanceOf(ConfigurationObject::class, $configuration);

        $this->assertSame([], $configuration->pathsFor('getSomethingNobodyWrote'));
    }

    public function test_a_file_that_is_not_a_configuration_object_yields_nothing(): void
    {
        $this->assertNotInstanceOf(
            ConfigurationObject::class,
            ConfigurationObject::fromFile(__DIR__ . '/../Fixtures/Rules/ForbiddenStaticConstFetchRule.php', 'whatever'),
        );

        $this->assertNotInstanceOf(
            ConfigurationObject::class,
            ConfigurationObject::fromFile(__DIR__ . '/no-such-file.php', 'whatever'),
        );
    }
}
