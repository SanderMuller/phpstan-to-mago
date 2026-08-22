<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;

/**
 * What a declaration says about itself, read from the syntax tree.
 *
 * Split out of {@see DeclaredParameters} because these answers are about the tree and not about coverage, and
 * because two of them are only true by construction *because* they come from the tree: a parameter carries a
 * `Hint` child exactly when a type is written, which is what php-parser's `$param->type` tests, and a closure
 * is reachable here where `Codebase` has no name to enumerate it under.
 *
 * Every shape below was probed rather than assumed, in `internal/probe-param-cst.php`: which children a
 * `FunctionLikeParameter` has, which of them `...` and `&` are *not*, and that a docblock is trivia rather
 * than a node.
 */
final class Declarations
{
    /**
     * Kinds that can hold a member declaration or a `use` of a trait.
     *
     * `AnonymousClass` is one of them: a trait used there is used by a class, and a method declared there is
     * subject to the same LSP guard as any other.
     *
     * @var list<NodeKind>
     */
    public const array CLASS_LIKES = [NodeKind::Class_, NodeKind::Interface, NodeKind::Trait, NodeKind::Enum, NodeKind::AnonymousClass];

    /**
     * The parameters a function-like declares itself, not those of a closure inside its body.
     *
     * The list is a *direct* child of the function-like, so no descendant search is needed — and a descendant
     * search would be wrong, because a method holding a closure would collect the closure's parameters too and
     * then count them again when the closure's own turn came.
     *
     * @return list<Node>
     */
    public static function ownParameters(SourceFile $source, Node $functionLike): array
    {
        foreach ($source->getChildren($functionLike) as $child) {
            if ($child->kind !== NodeKind::FunctionLikeParameterList) {
                continue;
            }

            $parameters = [];
            foreach ($source->getChildren($child) as $parameter) {
                if ($parameter->kind === NodeKind::FunctionLikeParameter) {
                    $parameters[] = $parameter;
                }
            }

            return $parameters;
        }

        return [];
    }

    /** Whether a written type stands in front of the parameter's variable. */
    public static function hasTypeHint(SourceFile $source, Node $parameter): bool
    {
        foreach ($source->getChildren($parameter) as $child) {
            if ($child->kind === NodeKind::Hint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the parameter is variadic.
     *
     * `...` is not a child node — probed, along with `&`, an attribute list, a promotion modifier and a
     * default value, which *are* nodes. So it is read from the text in front of the variable, and only from
     * there: a default value can hold `...` of its own, and that one is not this parameter's.
     */
    public static function isVariadic(SourceFile $source, Node $parameter): bool
    {
        foreach ($source->getChildren($parameter) as $child) {
            if ($child->kind !== NodeKind::DirectVariable) {
                continue;
            }

            return str_contains($source->getText(new Span($parameter->span->start, $child->span->start)), '...');
        }

        return false;
    }

    /**
     * Whether the function-like's own docblock declares a `callable` parameter.
     *
     * The collector skips those, because a `callable` can be anything. A docblock is trivia rather than a
     * node, so `getTrivia()` is the only route to it, and "its own" means the nearest one with nothing but
     * whitespace between — otherwise the docblock of the member above would answer for this one.
     */
    public static function declaresCallableParameter(SourceFile $source, Node $functionLike): bool
    {
        foreach ($source->getTrivia() as $trivia) {
            if ($trivia->kind !== TriviaKind::DocBlockComment || $trivia->span->end > $functionLike->span->start) {
                continue;
            }

            if (trim($source->getText(new Span($trivia->span->end, $functionLike->span->start))) !== '') {
                continue;
            }

            return str_contains($source->getText($trivia->span), '@param callable');
        }

        return false;
    }

    /** The identifier a declaration writes for itself. */
    public static function declaredName(SourceFile $source, Node $declaration): ?string
    {
        foreach ($source->getChildren($declaration) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getText($child);
            }
        }

        return null;
    }

    /** The innermost method declaration around a node, or null when it is not inside one. */
    public static function enclosingMethod(SourceFile $source, Node $node): ?Node
    {
        $method = null;
        foreach ($source->getAncestors($node) as $ancestor) {
            if ($ancestor->kind !== NodeKind::Method) {
                continue;
            }

            if (! $method instanceof Node || $method->span->contains($ancestor->span)) {
                $method = $ancestor;
            }
        }

        return $method;
    }

    /**
     * The class-like declaration a member is written in, innermost first.
     *
     * Innermost, so a member of a class declared inside a method is attributed to that class and not to the
     * one around it — and `AnonymousClass` counts, because a trait used there is used by a class.
     */
    public static function enclosingClassLike(SourceFile $source, Node $member): ?Node
    {
        // Chosen by narrowest span rather than by position in the ancestor list, because the list's order is
        // not part of the SDK's contract. Taking the last entry read as "innermost" and returned the
        // *outermost* class for a method of an anonymous class declared inside another class's method — the
        // LSP guard then asked the wrong class and a control counted 4 where PHPStan counted 2.
        $owner = null;
        foreach ($source->getAncestors($member) as $ancestor) {
            if (! in_array($ancestor->kind, self::CLASS_LIKES, true)) {
                continue;
            }

            if (! $owner instanceof Node || $owner->span->contains($ancestor->span)) {
                $owner = $ancestor;
            }
        }

        return $owner;
    }

    /** The fully qualified name a class-like declaration resolves to, or null for an anonymous one. */
    public static function classLikeName(SourceFile $source, Node $declaration): ?string
    {
        foreach ($source->getChildren($declaration) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getResolvedName($child)?->name;
            }
        }

        return null;
    }
}
