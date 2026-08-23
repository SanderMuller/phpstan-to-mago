<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * The refusal used to name the method, which read as one table row away. It is not: a plugin's only window
 * onto a type is `Mago\Sdk\Analyzer\Type::__toString()`, and this measures what that window loses.
 *
 * Fifteen of twenty shapes render identically. The five that do not are four causes, and only the first is
 * formatting:
 *
 * - **member order for a nullable scalar** — `int|null` against `null|int`. Narrow: a nullable *class* agrees,
 *   and three of them sorting either side of the word `null` all agree, so it is not name-dependent;
 * - **intersections** keep only their first member;
 * - a **literal `true`** renders as `bool`, where PHPStan keeps the literal at this verbosity;
 * - a **generic** loses its parameters.
 *
 * The last three are information the string does not carry. `Type` exposes `getLiteralInt()`,
 * `getLiteralString()`, `getLiteralClassString()`, `getLiteralBool()`, `encode()`, `isRequestReference()` and
 * `__toString()` — there is no accessor for the atomic types, so there is nothing else to read.
 *
 * So `describe()` is refused, and this is why: a rule interpolating a type into its message cannot refuse
 * mid-analysis. It would print a right message on fifteen shapes and a wrong one on five, which is the
 * plausible-but-wrong rule this project exists to avoid emitting.
 *
 * Pinned as a test rather than written down, because the interesting event is the SDK *changing*. A row that
 * starts matching is the signal to revisit the refusal, and a dated note in a file nobody runs would not give
 * it.
 */
final class DescribesTypesLikePhpstanTest extends TestCase
{
    /** @var array<string, array{string, string}>|null */
    private static ?array $rendered = null;

    /**
     * Every probed shape, with what each tool renders it as.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function shapes(): iterable
    {
        $rows = [
            // Every scalar, and every class-like name, agrees. This is the part a port could rely on.
            'int' => ['probe_int', 'int', 'int'],
            'float' => ['probe_float', 'float', 'float'],
            'string' => ['probe_string', 'string', 'string'],
            'bool' => ['probe_bool', 'bool', 'bool'],
            'array' => ['probe_array', 'array', 'array'],
            'mixed' => ['probe_mixed', 'mixed', 'mixed'],
            'null' => ['probe_null', 'null', 'null'],
            'a class' => ['probe_class', 'TypeShapes\\Thing', 'TypeShapes\\Thing'],
            'an enum' => ['probe_enum', 'TypeShapes\\Suit', 'TypeShapes\\Suit'],
            'a union of scalars' => ['probe_union', 'int|string', 'int|string'],
            // `typeOnly()` collapses a literal string and a literal int, and so does Mago — so these agree for
            // a different reason than they look like they do.
            'a literal string' => ['probe_literal_string', 'string', 'string'],
            'a literal int' => ['probe_literal_int', 'int', 'int'],
            // A nullable *class* agrees, and the three of them are here to say the ordering below is not about
            // the name: `Aaa`, `Thing` and `Zzz` sort either side of the word `null` and all three put the
            // class first, the way PHPStan does.
            'a nullable class' => ['probe_nullable', 'TypeShapes\\Thing|null', 'TypeShapes\\Thing|null'],
            'a nullable class sorting before null' => ['probe_nullable_early', 'TypeShapes\\Aaa|null', 'TypeShapes\\Aaa|null'],
            'a nullable class sorting after null' => ['probe_nullable_late', 'TypeShapes\\Zzz|null', 'TypeShapes\\Zzz|null'],

            // And the five that do not agree.
            'a nullable scalar' => ['probe_nullable_scalar', 'int|null', 'null|int'],
            'a docblock intersection' => ['probe_intersection', 'TypeShapes\\Alpha&TypeShapes\\Beta', 'TypeShapes\\Alpha'],
            // The control that makes the row above mean something: written as a real PHP intersection rather
            // than in a docblock it renders the same way, so Mago is not merely ignoring the docblock.
            'a native intersection' => ['probe_native_intersection', 'TypeShapes\\Alpha&TypeShapes\\Beta', 'TypeShapes\\Alpha'],
            'a literal true' => ['probe_literal_bool', 'true', 'bool'],
            'a generic list' => ['probe_call', 'list<TypeShapes\\Thing>', 'list'],
        ];

        foreach ($rows as $label => $row) {
            yield $label => $row;
        }
    }

    #[DataProvider('shapes')]
    public function test_renders_the_shape_the_way_each_tool_does(string $callee, string $phpstan, string $mago): void
    {
        $rendered = $this->rendered();

        $this->assertArrayHasKey($callee, $rendered, "Neither tool probed {$callee}, so the fixture no longer reaches it.");

        // Both sides asserted, so an upstream change on either one fails here rather than silently redefining
        // what the other has to match — the same discipline the parameter controls hold to.
        $this->assertSame($phpstan, $rendered[$callee][0], "PHPStan no longer renders {$callee} as {$phpstan}.");
        $this->assertSame($mago, $rendered[$callee][1], "Mago no longer renders {$callee} as {$mago}. If it now matches PHPStan, the describe() refusal can be revisited.");
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
     * @return array<string, array{string, string}>
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
