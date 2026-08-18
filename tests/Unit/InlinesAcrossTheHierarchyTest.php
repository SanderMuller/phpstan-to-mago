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

    private function emit(string $rule): string
    {
        return (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile()['rust'];
    }
}
