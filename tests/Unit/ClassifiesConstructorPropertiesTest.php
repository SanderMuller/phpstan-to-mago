<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * What a rule's constructor properties are, and what each one costs.
 *
 * All five hihaho rules that used to refuse with `unknown local $this` were saying the same uninformative
 * thing about three different problems: a configured value the generated plugin could carry, a value derived
 * in the constructor body, and a PHPStan service with no injectable equivalent. Only the first is portable,
 * and the refusal has to say which it is — a message that names the obstacle is the difference between a
 * refusal you can act on and one you can only stare at.
 *
 * Asserted through the refusal messages rather than the internals: the message is the product here, and it
 * is what a person reads when a rule does not port.
 */
final class ClassifiesConstructorPropertiesTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/ConfiguredPackage/src/Rules';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a property holding a PHPStan service names the service' => [
            'ServiceBackedRule',
            'holds the PHPStan service reflectionProvider',
        ];

        yield 'a derivation reaching outside the pure set says which obstacle it hit' => [
            'ImpureDerivationRule',
            'the derivation reaches outside the set the generated constructor can carry',
        ];

        yield 'a property derived from a service names the service, not the derivation' => [
            'DerivedFromServiceRule',
            'holds the PHPStan service reflectionProvider',
        ];
    }

    #[DataProvider('refusals')]
    public function test_refuses_naming_the_obstacle(string $rule, string $expected): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile();
    }

    public function test_a_configured_rule_emits_a_constructor_carrying_the_packages_defaults(): void
    {
        $emitted = (new Transpiler(self::RULES . '/ConfiguredRule.php'))->transpile()['rust'];

        // The defaults come from the rule package's own neon — `['App', 'Tests']` and `3` — so a worker that
        // constructs the plugin with no arguments behaves like PHPStan at package defaults.
        $this->assertStringContainsString("public readonly array \$namespaces = ['App', 'Tests'],", $emitted);
        $this->assertStringContainsString('public readonly int $limit = 3,', $emitted);
        $this->assertStringContainsString('$this->namespaces === []', $emitted);
    }

    public function test_a_phpstan_service_never_becomes_a_constructor_parameter(): void
    {
        $emitted = (new Transpiler(self::RULES . '/ConfiguredRule.php'))->transpile()['rust'];

        $this->assertStringNotContainsString(
            'reflectionProvider',
            $emitted,
            'A service has no injectable equivalent, so asking a worker for one would emit a plugin nobody '
            . 'can construct.',
        );
    }

    public function test_the_generated_plugin_is_free_of_any_consuming_projects_configuration(): void
    {
        $emitted = (new Transpiler(self::RULES . '/ConfiguredRule.php'))->transpile()['rust'];

        // Only the rule package's own values appear. Reading a consumer's `phpstan.neon` at transpile time
        // would tie the generated file to one project; the consumer overrides through its worker instead.
        $this->assertStringNotContainsString('phpstan.neon', $emitted);
        $this->assertStringContainsString("= ['App', 'Tests']", $emitted);
    }

    public function test_a_pure_derivation_is_carried_verbatim_into_the_constructor(): void
    {
        $emitted = (new Transpiler(self::RULES . '/DerivedPropertyRule.php'))->transpile()['rust'];

        // The generated plugin is PHP and the rule's own parameter names are kept, so a derivation over
        // configured values, literals and pure functions is the same code rather than a translation of it.
        $this->assertStringContainsString('private readonly array $lookup;', $emitted);
        $this->assertStringContainsString('$this->lookup = array_fill_keys($namespaces, true);', $emitted);
        $this->assertStringContainsString("public readonly array \$namespaces = ['App', 'Tests'],", $emitted);
    }

    public function test_only_the_php_target_carries_a_derivation(): void
    {
        // Rust has no equivalent of copying PHP verbatim, and the two Rust targets exist to check that a
        // change to body translation altered nothing. Claiming a derivation there would emit Rust that does
        // something else.
        Transpiler::$target = 'analyzer';

        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/only the PHP target can carry/');

        (new Transpiler(self::RULES . '/DerivedPropertyRule.php'))->transpile();
    }

    public function test_a_rule_whose_package_wires_nothing_says_so_rather_than_blaming_the_derivation(): void
    {
        // A fact about the package, not a gap in the transpiler, and the two read differently to whoever is
        // deciding what to fix next.
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/wires no configured values for this rule/');

        (new Transpiler(self::RULES . '/UnwiredDerivationRule.php'))->transpile();
    }

    public function test_a_property_a_package_does_not_wire_names_the_missing_wiring(): void
    {
        // The package's neon is the only source of truth for what a value is. Where it says nothing, the
        // transpiler has no default to carry and no service to name, so there is no value to carry — and
        // inventing a default would be a guess dressed as configuration.
        //
        // The refusal has to name *that*. It used to fall through to `unknown local $this`, which points at the
        // receiver: `hihaho/phpstan-rules` registers only two of its four positional-flag rules, and the other
        // two refused that way — reading as a vocabulary gap in the rule's body rather than as a fact about the
        // package.
        $this->expectException(Refusal::class);
        $this->expectExceptionMessageMatches('/\$whatever is a constructor parameter the package.s neon does not wire/');

        (new Transpiler(self::RULES . '/UnwiredPropertyRule.php'))->transpile();
    }
}
