<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectWithMethodType;
use Mago\Sdk\Analyzer\Type\ReferenceType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;

/**
 * An inferred type as PHPStan's `describe(VerbosityLevel::typeOnly())` writes it.
 *
 * 27 rule classes in the installed packages interpolate a rendered type into their message, so a port that
 * renders differently is wrong in the way a reader notices first. `Type::__toString()` is the obvious
 * candidate and is not good enough: measured over 243822 types at the positions those rules read from,
 * **22868 — 9.38 % — render differently**. A generic loses its parameters (14003), an intersection collapses
 * to its first member (6395), and a nullable scalar comes back with its members reversed (2595).
 *
 * So this renders from `Type::$atomicTypes`, which still carries all three. It is built to be *total*,
 * because a rule interpolating a type cannot refuse half way through an analysis: an atomic kind with no
 * branch here falls back to that atomic's own `__toString()` rather than to nothing, and
 * {@see DescribesTypesLikePhpstanTest} gates the set of kinds that have a branch against the 24 a real
 * corpus reaches.
 *
 * @see tests/Support/run-render-census.php for the counts, which are re-runnable
 */
final class Describe
{
    /**
     * The keyword PHPStan prints for each scalar kind.
     *
     * `ClassLikeString` is `string` here rather than `class-string`, because `typeOnly()` prints the former:
     * measured, `Foo::class` describes as `string` and its atomic is a `ClassLikeString`.
     */
    private const array SCALARS = [
        'Scalar' => 'scalar',
        'Numeric' => 'numeric',
        'ArrayKey' => 'array-key',
        'Boolean' => 'bool',
        'Integer' => 'int',
        'Float' => 'float',
        'String' => 'string',
        'ClassLikeString' => 'string',
    ];

    /** The keyword for each of the four kinds that carry nothing but a name. */
    private const array SIMPLE = [
        'Never' => 'never',
        'Null' => 'null',
        'Void' => 'void',
        'Placeholder' => 'mixed',
    ];

    /**
     * A type as text, or null when there is no type.
     *
     * **Union members are ordered the way PHPStan orders them, and that order is derived rather than
     * guessed.** `UnionTypeHelper::sortTypes()` in `phpstan.phar` is the authority; the reachable part of its
     * comparator is reproduced in {@see compareMembers()}. Keeping Mago's own order with `null` moved last
     * was what this did before, and the docblock claimed that placement was *the whole* of the divergence —
     * the corpus differential then printed 18 union-order mismatches beyond it, `int|false` against
     * `false|int` among them.
     *
     * This reorders and renders nothing differently, so it changes no emitted byte: a plugin calls
     * `Support::describeType()` and the ordering happens inside the runtime.
     *
     * No empty-union fallback, because there is no empty union: the SDK declares `$atomicTypes` as
     * `non-empty-list<AtomicType>`, so the loop below always yields a member. The `(string) $type` fallback
     * this replaced was unreachable for the same reason and only looked live because it tested an array
     * built from two halves.
     */
    public static function type(?Type $type): ?string
    {
        if (! $type instanceof Type) {
            return null;
        }

        $members = [];
        foreach ($type->atomicTypes as $atomic) {
            $members[] = [$atomic, self::atomic($atomic)];
        }

        usort($members, self::compareMembers(...));

        return implode('|', array_values(array_unique(array_map(
            static fn (array $member): string => $member[1],
            $members,
        ))));
    }

    /**
     * One step of PHPStan's union ordering, for the members this renderer can produce.
     *
     * Read off `UnionTypeHelper::sortTypes()` and reduced to the cases reachable here. Its full comparator
     * also orders accessory types, constant arrays by emptiness, enum cases by `Class::CASE` and integer
     * ranges by their minimum; none of those is a member this renderer emits distinctly, so reproducing them
     * would be writing against a shape nothing produces.
     *
     * The three that are reachable, in the original's order of precedence:
     *
     * - **`null` last.** The one rule this already had.
     * - **A boolean carrying a literal after everything else.** `ConstantBooleanType` sorts last but for
     *   null, which is why the original writes `int|false` where Mago's own order gives `false|int`.
     * - **A literal scalar before a non-literal one**, which is `ConstantScalarType` against the rest — and
     *   it comes *after* the boolean rule, because a literal `false` is both and the boolean rule wins.
     *
     * Everything else falls to the original's own tail: compare the rendered text case-insensitively, then
     * binary as the tie-break. That is what puts a union of class names in alphabetical order.
     *
     * @param array{0: mixed, 1: string} $a
     * @param array{0: mixed, 1: string} $b
     */
    private static function compareMembers(array $a, array $b): int
    {
        foreach ([[$a, $b, 1], [$b, $a, -1]] as [$first, $second, $sign]) {
            if ($first[1] === 'null' && $second[1] !== 'null') {
                return $sign;
            }

            if (self::isLiteralBoolean($first[0]) && ! self::isLiteralBoolean($second[0])) {
                return $sign;
            }

            if (self::isLiteralScalar($first[0]) && ! self::isLiteralScalar($second[0])) {
                return -$sign;
            }
        }

        // The original's tail: `strcasecmp` on the rendering, tie-broken by a binary compare so the order is
        // total rather than merely consistent.
        $insensitive = strcasecmp($a[1], $b[1]);

        return $insensitive !== 0 ? $insensitive : $a[1] <=> $b[1];
    }

    /** Whether an atomic is a boolean narrowed to `true` or `false`, which sorts last but for `null`. */
    private static function isLiteralBoolean(mixed $atomic): bool
    {
        return $atomic instanceof ScalarType
            && $atomic->kind === ScalarTypeKind::Boolean
            && is_bool($atomic->refinement);
    }

    /** Whether an atomic is a scalar narrowed to one written value, which sorts before one that is not. */
    private static function isLiteralScalar(mixed $atomic): bool
    {
        return $atomic instanceof ScalarType && $atomic->refinement !== null;
    }

    /** One atomic, with any intersection it carries joined onto it. */
    private static function atomic(AtomicType $atomic): string
    {
        $rendered = self::head($atomic);
        foreach (self::intersections($atomic) as $intersection) {
            $rendered .= '&' . self::head($intersection);
        }

        return $rendered;
    }

    /**
     * The intersection members an atomic carries, which `Type::__toString()` drops.
     *
     * Read defensively: only some atomic classes declare the property, and a renderer that has to be total
     * cannot assume which.
     *
     * @return list<AtomicType>
     */
    private static function intersections(AtomicType $atomic): array
    {
        $intersections = get_object_vars($atomic)['intersections'] ?? null;
        if (! is_array($intersections)) {
            return [];
        }

        return array_values(array_filter($intersections, static fn (mixed $i): bool => $i instanceof AtomicType));
    }

    /**
     * One atomic without its intersections.
     *
     * The `default` arm is the fallback the totality argument rests on: an atomic kind nobody has mapped
     * renders as the SDK renders it, which is a name rather than a blank. A kind reaching it is a gap the
     * test names, not a crash a rule suffers.
     */
    private static function head(AtomicType $atomic): string
    {
        return match (true) {
            $atomic instanceof ScalarType => self::scalar($atomic),
            $atomic instanceof SimpleAtomicType => self::SIMPLE[$atomic->kind->name] ?? strtolower($atomic->kind->name),
            $atomic instanceof NamedObjectType => $atomic->name,
            $atomic instanceof EnumType => $atomic->name,
            $atomic instanceof GenericParameterType => $atomic->name,
            $atomic instanceof ReferenceType => $atomic->name ?? (string) $atomic,
            $atomic instanceof ObjectWithMethodType => 'object',
            $atomic instanceof AnyObjectType => 'object',
            $atomic instanceof MixedType => 'mixed',
            $atomic instanceof CallableType => 'callable',
            $atomic instanceof ResourceType => 'resource',
            $atomic instanceof KeyedArrayType => 'array',
            // The two generics `typeOnly()` still parameterises. `list<Thing>` is the shape a rule quotes;
            // `Type::__toString()` prints `list` and drops the element, which is 14003 of the 22868 sites.
            $atomic instanceof ListType => 'list<' . self::type($atomic->elementType) . '>',
            $atomic instanceof IterableType => 'iterable<' . self::type($atomic->keyType) . ', ' . self::type($atomic->valueType) . '>',
            default => (string) $atomic,
        };
    }

    /**
     * A scalar, and the one place a literal survives `typeOnly()`.
     *
     * PHPStan prints `true` and `false` rather than `bool`, and nothing else: a literal string prints as
     * `string` and a literal int as `int`, which is why only the boolean refinement is read here.
     */
    private static function scalar(ScalarType $atomic): string
    {
        if ($atomic->kind === ScalarTypeKind::Boolean && is_bool($atomic->refinement)) {
            return $atomic->refinement ? 'true' : 'false';
        }

        return self::SCALARS[$atomic->kind->name] ?? strtolower($atomic->kind->name);
    }
}
