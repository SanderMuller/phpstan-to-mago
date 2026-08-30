<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

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
    /** The kinds a `getFunction()` question can be asked about, in the order the tree nests them. */
    private const array FUNCTION_LIKE_KINDS = ['Method', 'Function', 'Closure', 'ArrowFunction'];

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
            if ($classLike !== null && $classLike->flags->contains(MetadataFlags::DEPRECATED)) {
                return true;
            }
        }

        $function = self::enclosingFunctionLike($context, $subject);
        if ($function === null) {
            return false;
        }

        [$kind, $name] = $function;

        // A method is looked up through its class and a plain function by name. A closure or arrow function
        // has neither, and PHPStan's `$scope->getFunction()` answers null inside one too — so both engines
        // say "no enclosing function" there rather than the port going quiet on a case the original sees.
        $metadata = $kind === 'Method' && $className !== null
            ? $context->codebase->getMethod($className, $name)
            : ($kind === 'Function' ? $context->codebase->getFunction($name) : null);

        return $metadata !== null && $metadata->flags->contains(MetadataFlags::DEPRECATED);
    }

    /**
     * The kind and name of the nearest enclosing function-like, or null at file scope.
     *
     * Walked the same way {@see Declares::enclosingClassName()} walks to a class-like, and stopping at the
     * first one: a method inside a closure inside a method is in the closure, which is what PHPStan answers
     * too.
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

            // A closure or arrow function has no name child, and that is the answer rather than a failure.
            return [$ancestor->kind->value, ''];
        }

        return null;
    }
}
