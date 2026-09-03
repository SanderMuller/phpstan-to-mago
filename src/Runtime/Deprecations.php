<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * `phpstan-deprecation-rules`' scope check, ported rather than translated.
 *
 * Every rule in that package opens with `$this->deprecatedScopeHelper->isScopeDeprecated($scope)`, which
 * exists so that deprecated code using deprecated things does not warn. The helper itself is a loop over
 * `DeprecatedScopeResolver[]`, and the package ships exactly one:
 * `DefaultDeprecatedScopeResolver` asks whether the enclosing class, trait or function is deprecated.
 *
 * So the port is at the helper, the same place and for the same reason `RuleLevel` sits at
 * `BooleanRuleHelper` — a loop over injected resolvers is not translatable, and the one resolver behind it
 * is three metadata reads.
 *
 * ## What that costs, said plainly
 *
 * The resolver list is extensible: a consumer can register another `DeprecatedScopeResolver` and this port
 * will not see it. Nothing in the four corpus packages or the two Laravel consumers does, and the provider
 * that builds the list is `LazyDeprecatedScopeResolverProvider` over whatever the container holds. A
 * consumer that adds one gets a port that reports where the original is silent, which is the *unsafe*
 * direction, so it is named here rather than left to be discovered.
 */
final class Deprecations
{
    /**
     * The kinds `$scope->getFunction()` can answer with, which does not include a closure.
     *
     * `MutatingScope::enterAnonymousFunction()` builds a closure's scope by passing `$scope->getFunction()`
     * straight through, so a closure inherits the enclosing named function rather than becoming one. Listing
     * `Closure` and `ArrowFunction` here made the walk stop at them and answer "no enclosing function",
     * which reads a `@deprecated` method's closure as an undeprecated scope — and every rule in the
     * deprecation package opens with that check, so the port reported where PHPStan is quiet. The pair under
     * `examples/FetchingDeprecatedConstRule` holds the case.
     */
    private const array FUNCTION_LIKE_KINDS = ['Method', 'Function'];

    /**
     * Whether the enclosing class, trait or function carries a deprecation.
     *
     * The three questions `DefaultDeprecatedScopeResolver` asks, in its order. `getClassLike()` answers the
     * first two together — PHPStan splits `getClassReflection()` from `getTraitReflection()` because a trait
     * is not a class to it, and mago has one accessor for both, so the port asks once.
     */
    public static function scopeIsDeprecated(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $className = Declares::enclosingClassName($context, $subject);
        if ($className !== null) {
            $classLike = $context->codebase->getClassLike($className);
            if ($classLike instanceof ClassLikeMetadata && $classLike->flags->contains(MetadataFlags::DEPRECATED)) {
                return true;
            }
        }

        $function = self::enclosingFunctionLike($context, $subject);
        if ($function === null) {
            return false;
        }

        [$kind, $name] = $function;

        // A method is looked up through its class and a plain function by name. A closure is walked past
        // rather than stopped at, so the function found here is the one PHPStan's scope carries.
        $metadata = $kind === 'Method' && $className !== null
            ? $context->codebase->getMethod($className, $name)
            : ($kind === 'Function' ? $context->codebase->getFunction($name) : null);

        return $metadata instanceof FunctionLikeMetadata && $metadata->flags->contains(MetadataFlags::DEPRECATED);
    }

    /**
     * The kind and name of the nearest enclosing function-like, or null at file scope.
     *
     * Walked the same way {@see Declares::enclosingClassName()} walks to a class-like, and stopping at the
     * first *named* one. A closure is not one: PHPStan's scope carries the function a closure was written
     * in, not the closure.
     *
     * @return array{string, string}|null
     */
    private static function enclosingFunctionLike(NodeAnalysisContext $context, Part|Node|null $subject): ?array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        [$file, $located] = Tree::locate($context, $node);

        foreach ([$located, ...$file->getAncestors($located)] as $ancestor) {
            if (! in_array($ancestor->kind->value, self::FUNCTION_LIKE_KINDS, true)) {
                continue;
            }

            foreach ($file->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                    $resolved = $file->getResolvedName($child);

                    return [$ancestor->kind->value, $resolved instanceof ResolvedName ? $resolved->name : trim($file->getText($child))];
                }
            }

            // A named function-like with no name child cannot happen, but answering rather than falling
            // through keeps the walk from continuing past the function the scope is in.
            return [$ancestor->kind->value, ''];
        }

        return null;
    }
}
