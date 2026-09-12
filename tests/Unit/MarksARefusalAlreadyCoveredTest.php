<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\PackageCoverage;
use Sandermuller\PhpstanToMago\ReportedIdentifiers;
use Sandermuller\PhpstanToMago\RuleOutcome;
use Sandermuller\PhpstanToMago\Tests\Support\LockedCorpus;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A refusal whose check a sibling already emits says so, and one whose check nothing emits does not.
 *
 * `hihaho/phpstan-rules` ships standalone rules and merged `Combined*` rules holding the same checks,
 * registers only the merged ones, and the standalone ones refuse on their unwired constructor parameter.
 * Each refusal is accurate about its own rule and each reads as a gap, which is the shape
 * {@see Transpiler::refuseASubsumedCollector()} was added for once already —
 * five collectors refused on whichever construct their bodies tripped on first.
 *
 * **The reject side is asserted, not assumed.** A pass that marks nothing produces an empty census diff, and
 * a snapshot test reads that as "nothing moved" rather than as "the pass never looked" — which is exactly
 * what happened on the first attempt here, because the sibling map was built from `RulePaths::expand()` and
 * that yields rules, not the traits the shared checks live in. So the second provider below names refusals
 * that must stay unmarked, and a version of this pass that marks everything fails on them.
 *
 * **The target is pinned, because a verdict here depends on ambient static state.**
 * `PackageCoverage` transpiles each rule to decide its verdict, and `Transpiler::$target` and `$survey` are
 * static — so a test running earlier in the suite decides what this one measures. Left unpinned, all seven
 * rows above passed in isolation and returned an empty marking in the full suite, with
 * `ClassCoversExistsRule` reading as refused. `TracksUpstreamDriftTest` pins them for the same reason and is
 * the convention followed here.
 *
 * @see ReadsAReferenceMarkerTest for the same by-position control discipline on a probe
 */
#[CoversClass(ReportedIdentifiers::class)]
#[CoversClass(PackageCoverage::class)]
final class MarksARefusalAlreadyCoveredTest extends TestCase
{
    /** @var array<string, RuleOutcome>|null */
    private static ?array $hihaho = null;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function coveredRefusals(): iterable
    {
        yield 'the unsafe request data check' => ['NoUnsafeRequestDataRule', ['CombinedMethodCallRule']];
        yield 'the unsafe facade check' => ['NoUnsafeRequestFacadeRule', ['CombinedStaticCallRule']];
        yield 'the unsafe helper check' => ['NoUnsafeRequestHelperRule', ['CombinedFuncCallRule']];
        yield 'the form-request field check' => ['UnvalidatedFormRequestFieldRule', ['CombinedMethodCallRule']];
        // One each, not the four rules that share the identifier. `hihaho.conventions.positionalFlagArgument`
        // is reported by every rule using the trait, on four different node types, and only the sibling
        // hooking the *same* type carries this rule's check: a positional flag on a method call is not
        // covered by a rule that looks at `new` or at a nullsafe call. The first version of this test
        // expected all four, because the pass it was written against compared identifiers alone.
        yield 'the positional flag on a method call' => ['PositionalFlagArgumentMethodCallRule', ['CombinedMethodCallRule']];
        yield 'the positional flag on a static call' => ['PositionalFlagArgumentStaticCallRule', ['CombinedStaticCallRule']];
    }

    /**
     * A pair sharing an identifier on *different* node types, which must not be marked.
     *
     * The row that caught the pass comparing identifiers alone. `ClassMethodCoversExistsRule` reports
     * `phpunit.covers` on a method's docblock and `ClassCoversExistsRule` reports it on a class's, so the
     * moment the class rule emitted the method rule was marked as covered by it — a plausible row saying a
     * check is carried when only the name is shared. Different package, so it is asserted through its own
     * coverage rather than the hihaho one.
     */
    public function test_a_shared_identifier_on_another_node_type_is_not_coverage(): void
    {
        $this->pinTheTarget();

        $keyed = [];
        foreach (PackageCoverage::forPackage(
            'phpstan/phpstan-phpunit',
            dirname(__DIR__, 2) . '/vendor/phpstan/phpstan-phpunit',
        )->outcomes as $outcome) {
            $keyed[$outcome->name] = $outcome;
        }

        $this->assertSame(RuleOutcome::EMIT, $keyed['ClassCoversExistsRule']->verdict);
        $this->assertSame(RuleOutcome::REFUSE, $keyed['ClassMethodCoversExistsRule']->verdict);
        $this->assertSame(
            [],
            $keyed['ClassMethodCoversExistsRule']->alsoEmittedBy,
            'The class rule reports the same identifiers on a class-like and the method rule reports them on '
            . 'a method, so neither carries the other`s check.',
        );
    }

    /**
     * Refusals in the same package that nothing emits, so the mark has a side it does not appear on.
     *
     * @return iterable<string, array{string}>
     */
    public static function uncoveredRefusals(): iterable
    {
        // Reports four identifiers of its own and no other rule in the package reports any of them.
        yield 'the migration DDL rule' => ['SlowMigrationDdlRule'];
    }

    /** @param list<string> $expected */
    #[DataProvider('coveredRefusals')]
    public function test_names_the_rules_that_already_carry_the_check(string $rule, array $expected): void
    {
        $outcome = $this->outcome($rule);

        $this->assertSame(RuleOutcome::REFUSE, $outcome->verdict, "{$rule} no longer refuses, so this row is stale.");
        $this->assertSame($expected, $outcome->alsoEmittedBy);
    }

    #[DataProvider('uncoveredRefusals')]
    public function test_leaves_a_refusal_nothing_covers_unmarked(string $rule): void
    {
        $outcome = $this->outcome($rule);

        $this->assertSame(RuleOutcome::REFUSE, $outcome->verdict, "{$rule} no longer refuses, so this row is stale.");
        $this->assertSame(
            [],
            $outcome->alsoEmittedBy,
            "Nothing in the package reports {$rule}'s identifiers, so marking it says a check is covered when "
            . 'it is not.',
        );
    }

    /**
     * An identifier built by interpolation is not read, which keeps the mark under-reporting.
     *
     * The debug rules spell theirs `"hihaho.debug.noDebugIn{$namespace}"`, so none of them contributes an
     * identifier here. That is the safe direction: an unmarked refusal reads as a gap, which is what a
     * refusal reads as anyway, where a wrongly marked one would say a check is covered when it is not.
     */
    public function test_reads_literal_identifiers_only(): void
    {
        $outcomes = $this->outcomes();

        $this->assertArrayHasKey('ChainedNoDebugInNamespaceRule', $outcomes);
        $this->assertSame(
            [],
            ReportedIdentifiers::of($outcomes['ChainedNoDebugInNamespaceRule']->file, []),
            'An interpolated identifier was read as a literal, which would let the mark claim coverage from '
            . 'a name that is only a prefix.',
        );
    }

    /** The census's own target, so a verdict does not depend on which test ran before this one. */
    private function pinTheTarget(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    private function outcome(string $rule): RuleOutcome
    {
        // A `--prefer-lowest` leg installs older rule packages whose rules differ, so a row naming a rule
        // that does not exist there is a fact about the corpus rather than a stale assertion. The same guard
        // `ReportsInstalledCoverageTest` and the census assertion use, honouring the same deliberate-drift
        // escape.
        $mismatch = LockedCorpus::mismatch();
        if ($mismatch !== null) {
            self::markTestSkipped($mismatch);
        }

        $outcomes = $this->outcomes();
        if (! isset($outcomes[$rule])) {
            self::fail("{$rule} is no longer in the package, so this row is stale.");
        }

        return $outcomes[$rule];
    }

    /** @return array<string, RuleOutcome> */
    private function outcomes(): array
    {
        if (self::$hihaho !== null) {
            return self::$hihaho;
        }

        $this->pinTheTarget();

        $keyed = [];
        foreach (PackageCoverage::forPackage(
            'hihaho/phpstan-rules',
            dirname(__DIR__, 2) . '/vendor/hihaho/phpstan-rules',
        )->outcomes as $outcome) {
            $keyed[$outcome->name] = $outcome;
        }

        return self::$hihaho = $keyed;
    }
}
