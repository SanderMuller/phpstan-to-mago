<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ReferenceType;
use Mago\Sdk\Analyzer\Type\ReferenceTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;

/**
 * Whether a type names something Rector's own build autoloads, which is `RectorAllowedAutoloadedTypeAnalyzer`.
 *
 * The original branches on PHPStan's type classes — `UnionType`, `ConstantStringType`, `ObjectType`,
 * `GenericClassStringType` — and there is no statement-by-statement translation of that, so the *question* is
 * ported instead. Every branch was measured against mago rather than read across, because the two models
 * disagree about which shape a written expression produces:
 *
 * | written              | PHPStan               | mago atomic                                  |
 * | -------------------- | --------------------- | -------------------------------------------- |
 * | `Alpha::class`       | `ConstantStringType`  | `ClassLikeString`, variant `Literal`          |
 * | `'TS\Alpha'`         | `ConstantStringType`  | `String`, `literalValue` on the refinement    |
 * | `class-string<Alpha>` | `GenericClassStringType` | `ClassLikeString`, variant `OfType`, `constraint` |
 * | `class-string`       | `ClassStringType`     | `ClassLikeString`, variant `Any`              |
 * | a class outside the analysed set | `ObjectType` | `ReferenceType`, kind `Symbol`            |
 *
 * The `class-string` row is the one that would have been guessed wrong: a bare `class-string` is not a
 * `GenericClassStringType` to PHPStan and names no class, so it is not allowed — and mago spells it with the
 * same atomic kind as the two rows that are.
 */
final class RectorAutoloadedTypes
{
    /** @see https://regex101.com/r/BBm9bf/1 — the analyzer's own pattern, copied rather than restated */
    private const string AUTOLOADED_CLASS_PREFIX_REGEX = '#^(PhpParser|PHPStan|Rector|Reflection|Symfony\\\\Component\\\\Console)#';

    /** @var list<string> */
    private const array ALLOWED_CLASSES = ['PhpParser\Node', 'PHPStan\PhpDocParser\Ast\Node'];

    /**
     * `RectorAllowedAutoloadedTypeAnalyzer::isAllowedType()`.
     *
     * The original's union branch is `array_all()`, and mago models a union as several atomics of one type, so
     * every atomic has to be allowed. That folds the union case and the single case into one loop rather than
     * two, which is the same reduction {@see Types::typeIsBoolean()} makes for the same reason.
     *
     * A type with no atomics answers no. PHPStan reaches `return false` for a `MixedType` too, so a subject
     * nothing is known about is reported by the original as well — but where mago simply failed to infer what
     * PHPStan did, this reports and the original does not. That direction is the risk this shape carries, and
     * it is the same one every inferred-type question in the runtime carries.
     */
    public static function isAllowed(NodeAnalysisContext $context, ?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (! self::atomicIsAllowed($context, $atomic)) {
                return false;
            }
        }

        return true;
    }

    private static function atomicIsAllowed(NodeAnalysisContext $context, AtomicType $atomic): bool
    {
        // `$this` and `static` are `ThisType`/`StaticType` to PHPStan, neither of which extends `ObjectType`,
        // so the original falls through them to `return false`. The same distinction
        // {@see Types::typeIsNamedObject()} carries, reached from the other side.
        if ($atomic instanceof NamedObjectType) {
            return ! $atomic->isThis && ! $atomic->static && self::classIsAllowed($context, $atomic->name);
        }

        // A class mago could not resolve is still a class PHPStan gives an `ObjectType` for. Measured, and it
        // is the whole reason this rule's good example failed first: a `PHPStan\Type\ObjectType $type`
        // parameter arrives as `ReferenceType{Symbol, PHPStan\Type\ObjectType}` rather than as a
        // `NamedObjectType`, because the analysed set is the example file and the class lives in a vendor
        // directory nothing pointed mago at. Reading only the resolved shape reports every allowed class the
        // run happens not to have loaded, which is wider than the rule rather than narrower.
        if ($atomic instanceof ReferenceType) {
            return $atomic->kind === ReferenceTypeKind::Symbol
                && $atomic->name !== null
                && self::classIsAllowed($context, $atomic->name);
        }

        if (! $atomic instanceof ScalarType) {
            return false;
        }

        $refinement = $atomic->refinement;

        if ($atomic->kind === ScalarTypeKind::String) {
            return $refinement instanceof StringType
                && is_string($refinement->literalValue)
                && self::classIsAllowed($context, $refinement->literalValue);
        }

        if ($atomic->kind !== ScalarTypeKind::ClassLikeString || ! $refinement instanceof ClassLikeStringType) {
            return false;
        }

        if ($refinement->variant === ClassLikeStringVariant::Literal) {
            return is_string($refinement->literal) && self::classIsAllowed($context, $refinement->literal);
        }

        // `class-string<T>`, where the original recurses on `getGenericType()`. The constraint is one atomic
        // rather than a whole type here, which is why the recursion is on the atomic and not on `isAllowed()`.
        return $refinement->variant === ClassLikeStringVariant::OfType
            && $refinement->constraint instanceof AtomicType
            && self::atomicIsAllowed($context, $refinement->constraint);
    }

    /**
     * `isAllowedClassString()` — a name Rector's build autoloads, by prefix or by descent.
     *
     * The prefix test is case-sensitive, because the analyzer's regex carries no `i` flag. Names arrive
     * without a leading backslash from every shape the probe measured, and are ltrimmed anyway so that a
     * written `\Rector\Foo` cannot fail the anchor.
     *
     * `is_a($value, $allowed, true)` is answered from the codebase rather than from PHP's autoloader: the
     * classes this asks about are php-parser's and phpstan-phpdoc-parser's interfaces, which a static analyser
     * reads rather than loads. `getClassAncestors()` is the same call {@see Types::typeIsInstanceOf()} makes.
     * Probed: it carries implemented interfaces as well as parents, and it answers **lowercased** — which is
     * why the comparison folds case rather than using `in_array()`.
     *
     * It answers nothing for a class outside the analysed set, so this half of the test is silent exactly
     * where the `ReferenceType` branch above applies. That costs only a subclass of `PhpParser\Node` or of
     * the phpdoc-parser node declared in a namespace none of the prefixes cover, which the prefixes make an
     * unlikely shape rather than an impossible one.
     */
    private static function classIsAllowed(NodeAnalysisContext $context, string $value): bool
    {
        $value = ltrim($value, '\\');

        if (preg_match(self::AUTOLOADED_CLASS_PREFIX_REGEX, $value) === 1) {
            return true;
        }

        foreach (self::ALLOWED_CLASSES as $allowedClass) {
            if (strcasecmp($value, $allowedClass) === 0) {
                return true;
            }

            foreach ($context->codebase->getClassAncestors($value) as $ancestor) {
                if (strcasecmp($ancestor, $allowedClass) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
