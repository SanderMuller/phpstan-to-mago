<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A rule rarely declares the helper it calls.
 *
 * Real rule packages keep the logic in a trait or an abstract base and leave the rule as a shim: in
 * `hihaho/phpstan-rules`, 12 of 20 rules reach their helper through a trait and 3 through a parent.
 * Inlining only same-class helpers refused every one of them.
 */
final class InlinesAcrossTheHierarchyTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/Rules';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_inlines_a_helper_declared_in_a_trait(): void
    {
        $this->assertStringContainsString('Support::nameEquals', $this->emit('TraitHelperRule'));
    }

    public function test_inlines_a_helper_declared_in_a_parent_class(): void
    {
        $this->assertStringContainsString('Support::nameEquals', $this->emit('ParentHelperRule'));
    }

    /**
     * Where the helper is declared is not a behavioural difference, so it must not be an output one.
     */
    public function test_a_trait_and_a_parent_produce_the_same_plugin(): void
    {
        $trait = str_replace(['TraitHelperRule', 'trait-helper-rule', 'traitHelper'], 'X', $this->emit('TraitHelperRule'));
        $parent = str_replace(['ParentHelperRule', 'parent-helper-rule', 'parentHelper'], 'X', $this->emit('ParentHelperRule'));

        $this->assertSame($trait, $parent);
    }

    /**
     * Resolution follows PHP's own order, so a name that resolves nowhere is refused by name rather than
     * silently translated into something that would compile.
     */
    public function test_refuses_a_helper_that_no_class_in_the_hierarchy_declares(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('no method missingHelper() on the rule, its traits or its parents');

        $this->emit('MissingHelperRule');
    }

    /**
     * The upward walk for the package root stops at "." on a relative path, so every cross-file name
     * silently resolved to nothing. The rule below only emits if the trait was found.
     */
    public function test_resolves_from_a_relative_path(): void
    {
        $relative = 'tests/Fixtures/Rules/TraitHelperRule.php';

        $this->assertStringContainsString('Support::nameEquals', (new Transpiler($relative))->transpile()['rust']);
    }

    /**
     * The shape that blocks a real rule package: the helper returns the finding, not a boolean, so it has
     * to be inlined in statement position — its guards becoming the rule's guards and its returned error
     * becoming the rule's report.
     */
    public function test_inlines_a_helper_that_returns_the_finding(): void
    {
        $emitted = $this->emit('TraitErrorHelperRule');

        $this->assertStringContainsString('$context->report(', $emitted);
        $this->assertStringContainsString('Do not use a debug function', $emitted);
    }

    /**
     * Two guards each returning the same error report under either condition, which is one guard on their
     * disjunction followed by the single report the emitted rule already appends.
     */
    public function test_collects_report_guards_into_one_disjunction(): void
    {
        $emitted = $this->emit('TraitErrorHelperRule');

        $this->assertMatchesRegularExpression("/if \(!\(\(.+'dd'\) \|\| .+'dump'\)\)\)/", $emitted);
    }

    /**
     * The helper's trailing `return null` is the fall-through of those guards, not an exit. Emitting a
     * bail for it put an unconditional `return;` in front of the report, so the rule loaded, ran, and
     * silently found nothing — the failure mode that makes "it emitted" worthless as a result.
     *
     * Two guards means exactly two exits. A third is the bug.
     */
    public function test_emits_no_exit_that_makes_the_report_unreachable(): void
    {
        $body = substr($this->emit('TraitErrorHelperRule'), (int) strpos($this->emit('TraitErrorHelperRule'), 'public function analyze'));

        $this->assertSame(2, substr_count($body, 'return;'));
        $this->assertStringContainsString('$context->report(', $body);
    }

    /**
     * Depth is not the thing to guard against; a cycle is.
     *
     * A flat cap of 4 stood here and read as a recursion guard. It refused a terminating chain the moment one
     * more helper joined it — which is what `hihaho/phpstan-rules` v3.15.2 did, costing two rules that emitted
     * against 3.15.1 and reporting it as "nests deeper than 4".
     */
    public function test_a_chain_deeper_than_the_old_cap_still_reaches_its_predicate(): void
    {
        $emitted = $this->emit('DeepHelperChainRule');

        $this->assertStringContainsString('Support::nameEquals', $emitted);
        $this->assertStringContainsString("'forbidden'", $emitted);
    }

    public function test_two_helpers_that_call_each_other_are_refused_as_a_cycle(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/inlining ping\(\) reaches ping\(\) again/');

        $this->emit('CyclicHelperRule');
    }

    /**
     * The shape `hihaho/phpstan-rules` v3.15.2 introduced, materialised rather than folded.
     *
     * A record is ordinarily a transpile-time map of field to expression, which cannot leave the loop that
     * produced it: every expression reads the item the emitted `foreach` binds. Each field becomes a real
     * local instead — declared before the loop, assigned inside it, read after — which is the trade the
     * counter already makes for the same reason.
     */
    public function test_a_record_folded_inside_a_loop_becomes_a_local_per_field(): void
    {
        $emitted = $this->emit('FoldsARecordRule');

        // Declared ahead of the loop, so the read after it names something that exists.
        $this->assertStringContainsString('$site_method = null;', $emitted);
        $this->assertMatchesRegularExpression('/\$site_method = null;.*foreach \(/s', $emitted);

        // Assigned inside it, and copied into the accumulator rather than aliased to it.
        $this->assertStringContainsString('$record_method = $class_reflection;', $emitted);
        $this->assertStringContainsString('$site_method = $record_method;', $emitted);

        // Read after it, by the report the rule builds.
        $this->assertMatchesRegularExpression('/if \(\$site_method === null\) \{\s*return;/', $emitted);
        $this->assertStringContainsString('$site_method)', $emitted);
    }

    /**
     * The producer's own `return null` declines the rule here, and does not end the caller's iteration.
     *
     * `siteFor()` answers null for a class it cannot name, and the caller turns that into `return null` from
     * the accumulator — which leaves the rule. Emitting `continue` instead would go on to the next class, so
     * a receiver with two classes where the first produces nothing would report where PHPStan is silent.
     */
    public function test_a_producer_that_declines_inside_the_fold_bails_rather_than_continuing(): void
    {
        $emitted = $this->emit('FoldsARecordRule');

        $this->assertMatchesRegularExpression(
            '/if \(\$class_reflection === \x27\x27\) \{\s*return;/',
            $emitted,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/if \(\$class_reflection === \x27\x27\) \{\s*continue;/',
            $emitted,
        );
    }

    private function emit(string $rule): string
    {
        return (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile()['rust'];
    }
}
