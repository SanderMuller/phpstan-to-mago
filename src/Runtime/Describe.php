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
     * Union members keep Mago's order with one exception: `null` goes last. That is the whole of the
     * nullable-scalar divergence — `int|null` against `null|int` — and a nullable *class* already agrees,
     * which is what says the rule is about `null` rather than about sorting names.
     */
    public static function type(?Type $type): ?string
    {
        if (! $type instanceof Type) {
            return null;
        }

        $members = [];
        $nulls = [];
        foreach ($type->atomicTypes as $atomic) {
            $rendered = self::atomic($atomic);
            if ($rendered === 'null') {
                $nulls[] = $rendered;

                continue;
            }

            $members[] = $rendered;
        }

        $all = [...$members, ...$nulls];

        return $all === [] ? (string) $type : implode('|', array_values(array_unique($all)));
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
