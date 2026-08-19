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
