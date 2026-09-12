<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\TypeDescriptions;

/**
 * What a Mago plugin can say about an inferred type, against what PHPStan says about the same one.
 *
 * 28 rules across the installed packages render an inferred type into their message with
 * `$scope->getType($x)->describe(VerbosityLevel::typeOnly())` — the arithmetic and boolean-operand families,
 * `DisallowedLooseComparisonRule`, `UselessCastRule`, the `Variable*` family, `ArrayFilterStrictRule`,
 * `NarrowPrivateClassMethodParamTypeRule`. Only one reaches the refusal today; the rest stop earlier and have
 * this waiting behind whatever stops them first, which is why the boundary is worth measuring rather than
 * waiting for.
 *
 * Fifteen of twenty shapes render identically through `Mago\Sdk\Analyzer\Type::__toString()`. The five that do
 * not are four causes, and only the first is formatting:
 *
 * - **member order for a nullable scalar** — `int|null` against `null|int`. Narrow: a nullable *class* agrees,
 *   and three of them sorting either side of the word `null` all agree, so it is not name-dependent;
 * - **intersections** render only their first member;
 * - a **literal `true`** renders as `bool`, where PHPStan keeps the literal at this verbosity;
 * - a **generic** renders without its parameters.
 *
 * **None of that is information the SDK withholds, and an earlier version of this file said it was.**
 * `Type::$atomicTypes` is a public readonly property, and every one of the four divergences is recoverable
 * from it — measured, third column: the intersection's second member is on
 * `NamedObjectType::$intersections`, the literal is on `ScalarType::$refinement`, the element type is on
 * `ListType::$elementType`, and the reversed union has both its atomics. The claim was reached by grepping
 * `public function` and missing a promoted property, which is the same defect as a refusal naming the wrong
 * obstacle: it sent a reader toward filing an upstream request for an API that ships today.
 *
 * So `describe()` is refused because **no renderer over the atomics exists**, not because the model is missing
 * anything. A rule interpolating a type into its message cannot refuse mid-analysis, so shipping the lossy
 * rendering would be right on fifteen shapes and wrong on five — and building the renderer is work with a
 * corpus payoff of zero today. 27 of the 28 rules that render a type belong to
 * `phpstan/phpstan-strict-rules`, whose `rules.neon` gates every one behind `conditionalTags` keyed on
 * `%strictRules.allRules%`, and the measured consumer sets that to `false`. So they are switched off by
 * choice rather than by accident, and the denominator moves when someone flips the flag rather than when
 * anything here is fixed. The twenty-eighth is `type-coverage`'s `NarrowPrivateClassMethodParamTypeRule`,
 * which is registered and blocked earlier.
 *
 * Pinned as a test rather than written down, because the interesting events are the SDK's rendering changing
 * and the atomics ceasing to carry a fact a renderer would need. A dated note in a file nobody runs would
 * catch neither.
 */
#[Group('engine')]
final class DescribesTypesLikePhpstanTest extends TestCase
{
    /** @var array<string, array{string, string, string, string}>|null */
    private static ?array $rendered = null;

    /**
     * Every probed shape, with what each tool renders it as.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function shapes(): iterable
    {
        $rows = [
            // Every scalar, and every class-like name, agrees. This is the part a port could rely on.
            'int' => ['probe_int', 'int', 'int', 'atomics=1'],
            'float' => ['probe_float', 'float', 'float', 'atomics=1'],
            'string' => ['probe_string', 'string', 'string', 'atomics=1'],
            'bool' => ['probe_bool', 'bool', 'bool', 'atomics=1'],
            'array' => ['probe_array', 'array', 'array', 'atomics=1'],
            'mixed' => ['probe_mixed', 'mixed', 'mixed', 'atomics=1'],
            'null' => ['probe_null', 'null', 'null', 'atomics=1'],
            'a class' => ['probe_class', 'TypeShapes\\Thing', 'TypeShapes\\Thing', 'atomics=1'],
            'an enum' => ['probe_enum', 'TypeShapes\\Suit', 'TypeShapes\\Suit', 'atomics=1'],
            'a union of scalars' => ['probe_union', 'int|string', 'int|string', 'atomics=2'],
            // `typeOnly()` collapses a literal string and a literal int, and so does Mago — so these agree for
            // a different reason than they look like they do.
            'a literal string' => ['probe_literal_string', 'string', 'string', 'atomics=1'],
            'a literal int' => ['probe_literal_int', 'int', 'int', 'atomics=1'],
            // A nullable *class* agrees, and the three of them are here to say the ordering below is not about
            // the name: `Aaa`, `Thing` and `Zzz` sort either side of the word `null` and all three put the
            // class first, the way PHPStan does.
            'a nullable class' => ['probe_nullable', 'TypeShapes\\Thing|null', 'TypeShapes\\Thing|null', 'atomics=2'],
            'a nullable class sorting before null' => ['probe_nullable_early', 'TypeShapes\\Aaa|null', 'TypeShapes\\Aaa|null', 'atomics=2'],
            'a nullable class sorting after null' => ['probe_nullable_late', 'TypeShapes\\Zzz|null', 'TypeShapes\\Zzz|null', 'atomics=2'],

            // And the five that do not agree.
            'a nullable scalar' => ['probe_nullable_scalar', 'int|null', 'null|int', 'atomics=2'],
            'a docblock intersection' => ['probe_intersection', 'TypeShapes\\Alpha&TypeShapes\\Beta', 'TypeShapes\\Alpha', 'atomics=1 intersection=TypeShapes\\Beta'],
            // The control that makes the row above mean something: written as a real PHP intersection rather
            // than in a docblock it renders the same way, so Mago is not merely ignoring the docblock.
            'a native intersection' => ['probe_native_intersection', 'TypeShapes\\Alpha&TypeShapes\\Beta', 'TypeShapes\\Alpha', 'atomics=1 intersection=TypeShapes\\Beta'],
            'a literal true' => ['probe_literal_bool', 'true', 'bool', 'atomics=1 refinement=true'],
            'a generic list' => ['probe_call', 'list<TypeShapes\\Thing>', 'list', 'atomics=1 element=TypeShapes\\Thing'],
        ];

        foreach ($rows as $label => $row) {
            yield $label => $row;
        }
    }

    #[DataProvider('shapes')]
    public function test_renders_the_shape_the_way_each_tool_does(string $callee, string $phpstan, string $mago, string $recoverable): void
    {
        $rendered = $this->rendered();

        $this->assertArrayHasKey($callee, $rendered, "Neither tool probed {$callee}, so the fixture no longer reaches it.");

        // Both sides asserted, so an upstream change on either one fails here rather than silently redefining
        // what the other has to match — the same discipline the parameter controls hold to.
        $this->assertSame($phpstan, $rendered[$callee][0], "PHPStan no longer renders {$callee} as {$phpstan}.");
        $this->assertSame($mago, $rendered[$callee][1], "Mago no longer renders {$callee} as {$mago}. If it now matches PHPStan, the describe() refusal can be revisited.");

        // And what the atomics carry, which is what says the refusal is about a missing renderer rather than a
        // missing model. A row losing a fact here would make the renderer unbuildable for that shape, which is
        // a different and much worse finding than the rendering changing.
        $this->assertSame($recoverable, $rendered[$callee][2], "Mago's atomics no longer carry what its rendering of {$callee} drops.");

        // And the column that ships. Every shape, not only the five `Type::__toString()` gets wrong: a
        // renderer that has to be total is only worth having if it is right on the fifteen too.
        $this->assertSame(
            $phpstan,
            $rendered[$callee][3],
            "Describe::type() renders {$callee} as {$rendered[$callee][3]} where PHPStan writes {$phpstan}.",
        );
    }

    /**
     * The shapes are the whole vocabulary of the comparison, so a shape neither row names is a gap in it.
     *
     * Without this the fixture could grow a shape nobody compares, and the count in this file's docblock would
     * go stale while every test passed.
     */
    public function test_every_probed_shape_is_named_here(): void
    {
        $named = [];
        foreach (self::shapes() as [$callee]) {
            $named[] = $callee;
        }

        $this->assertSame([], array_values(array_diff(array_keys($this->rendered()), $named)));
        $this->assertCount(20, $named, 'The docblock says fifteen of twenty agree, so the row count is part of the claim.');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    private function rendered(): array
    {
        // Once per class: two tool runs, and every row comes out of the same pair.
        return self::$rendered ??= (new TypeDescriptions(
            __DIR__ . '/../Fixtures/types',
            dirname(__DIR__, 2),
        ))->rendered();
    }
}
