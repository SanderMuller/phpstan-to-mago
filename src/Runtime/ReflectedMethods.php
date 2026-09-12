<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\Visibility;

/**
 * What the codebase says about a method, addressed by class name and method name.
 *
 * Split out of {@see Members} when that class crossed its complexity limit. The seam is the one the
 * repository already uses: every method here reads metadata through {@see Mixins::declaringMethod()} and
 * none of them touches the tree, so the group is the transitive closure of a single lookup and cannot call
 * back into what it left. {@see Members} keeps the questions a rule asks of a *written* member.
 *
 * All of them answer about the *declaring* method rather than the class the rule asked from, which is what
 * PHPStan's own `getMethod()` does for an inherited method.
 */
final class ReflectedMethods
{
    private static function reflectedMethodVisibility(NodeAnalysisContext $context, ?string $class, ?string $method): ?Visibility
    {
        if ($class === null || $method === null) {
            return null;
        }

        $declaring = Mixins::declaringMethod($context->codebase, $class, $method);

        return $declaring instanceof FunctionLikeMetadata ? $declaring->visibility : null;
    }

    /** Whether the codebase's method is public. A method that is not found is not public. */
    public static function reflectedMethodIsPublic(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return self::reflectedMethodVisibility($context, $class, $method) === Visibility::Public;
    }

    /** Whether the codebase's method is private. */
    public static function reflectedMethodIsPrivate(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return self::reflectedMethodVisibility($context, $class, $method) === Visibility::Private;
    }

    /**
     * Whether the codebase's method is static, and the name it is declared under.
     *
     * Both read the *declaring* method through {@see Mixins::declaringMethod()}, the same lookup the
     * visibility predicates use, so an inherited method answers about where it is declared rather than about
     * the class the rule asked from  which is what PHPStan's own `getMethod()` does.
     *
     * `originalName` rather than `name`: metadata holds the lowercased form for lookups and the written form
     * beside it, and `MethodReflection::getName()` gives PHPStan the canonical casing. A message
     * interpolating the lowercased one diverges from the original on any method not spelled in lower case.
     */
    public static function reflectedMethodIsStatic(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        // `->static`, not `flags->contains(MetadataFlags::STATIC)`. The bit exists, is documented, and reads
        // false for a `public static function`  already probed and recorded on
        // {@see Types::typeIsStaticMethodReference()}, and reached for here anyway before the fires gate said
        // the plugin was silent on a static method it had otherwise resolved completely.
        return self::reflectedMethod($context, $class, $method)?->static === true;
    }

    /** The canonical name the codebase declares this method under. {@see reflectedMethodIsStatic()} */
    public static function reflectedMethodName(NodeAnalysisContext $context, ?string $class, ?string $method): ?string
    {
        return self::reflectedMethod($context, $class, $method)?->originalName;
    }

    /** The declaring method's metadata, or null when the class or the method is unknown. */
    private static function reflectedMethod(
        NodeAnalysisContext $context,
        ?string $class,
        ?string $method,
    ): ?FunctionLikeMetadata {
        if ($class === null || $method === null) {
            return null;
        }

        $declaring = Mixins::declaringMethod($context->codebase, $class, $method);

        return $declaring instanceof FunctionLikeMetadata ? $declaring : null;
    }
}
