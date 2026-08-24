<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * What a class-like, a method, a property or a constant *declares* about itself.
 *
 * Read from the tree rather than the codebase — {@see Reflect} answers similar-sounding questions from
 * metadata, and the two differ where it matters. A class writes `extends Foo`; the codebase knows it
 * descends from Foo, which includes what a trait or an interface brought in.
 */
final class Declares
{
    /** How far below a class-like to look for its members: body, then member list. */
    private const int MEMBER_DEPTH = 3;

    public static function declarationKindIs(NodeAnalysisContext $context, Part|Node|null $subject, string $kind): bool
    {
        $node = Tree::node($subject);

        return $node instanceof Node && $node->kind->value === $kind;
    }

    /**
     * The interfaces the enclosing class-like's declaration writes, which is `$classLike->implements`.
     *
     * `$directParentInterfaces`, not `$parentInterfaces`. Measured, because the two differ on exactly the case
     * these rules turn on: a class that implements nothing itself and extends one that implements `Target`
     * has an empty direct list and a populated transitive one. PHPStan reads the `implements` clause off the
     * declaration, so the direct list is the match and the transitive one would have made
     * `$classLike->implements !== []` true for a class whose declaration says nothing.
     *
     * Lowercased by the metadata, like every other name it holds.
     *
     * @return list<string>
     */
    public static function directInterfaceNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $className = self::enclosingClassName($context, $subject);
        if ($className === null) {
            return [];
        }

        $metadata = $context->codebase->getClassLike($className);

        return $metadata instanceof ClassLikeMetadata ? array_values(array_unique($metadata->directParentInterfaces)) : [];
    }

    /**
     * Every trait the enclosing declaration picks up, as metadata spells them.
     *
     * `usedTraits` is already transitive and already inherited — probed on a trait using a trait, and on a
     * subclass using neither itself, and both listed both — so this is the field, not a walk over it. The names
     * come back lowercased, which is why anything comparing against one folds case.
     *
     * @return list<string>
     */
    public static function usedTraitNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $className = self::enclosingClassName($context, $subject);
        if ($className === null) {
            return [];
        }

        $metadata = $context->codebase->getClassLike($className);

        return $metadata instanceof ClassLikeMetadata ? array_values(array_unique($metadata->usedTraits)) : [];
    }

    /**
     * Whether the enclosing declaration implements an interface, directly or through its hierarchy.
     *
     * `parentInterfaces` is the transitive set. Compared case-insensitively because metadata lowercases what it
     * holds while a configured or written name keeps the spelling its author used — which is the same folding
     * PHPStan gets by canonicalising both sides through reflection.
     */
    public static function classImplements(NodeAnalysisContext $context, Part|Node|null $subject, ?string $interface): bool
    {
        if ($interface === null) {
            return false;
        }

        $className = self::enclosingClassName($context, $subject);
        if ($className === null) {
            return false;
        }

        $metadata = $context->codebase->getClassLike($className);
        if (! $metadata instanceof ClassLikeMetadata) {
            return false;
        }

        foreach ($metadata->parentInterfaces as $implemented) {
            if (strcasecmp(ltrim($implemented, '\\'), ltrim($interface, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the enclosing declaration has a method of this name, anywhere in its hierarchy.
     *
     * Answered through the same declaring-class lookup a rule reading that class uses, so the two cannot
     * disagree: a name this says exists is a name that lookup can attribute.
     */
    public static function classHasMethod(NodeAnalysisContext $context, Part|Node|null $subject, ?string $method): bool
    {
        return Reflect::declaringClassOfMethod($context, self::enclosingClassName($context, $subject), $method) !== null;
    }

    /**
     * The name of the function or method the node sits in, or null outside one.
     *
     * What `$scope->getFunctionName()` gives a rule. A closure and an arrow function are anonymous, so a node
     * inside one has no enclosing *name* — the walk stops there rather than continuing to the method around it,
     * which is what PHPStan answers too.
     */
    public static function enclosingFunctionName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        [$file, $located] = Tree::locate($context, $node);

        foreach ([$located, ...$file->getAncestors($located)] as $ancestor) {
            if ($ancestor->kind === NodeKind::Closure || $ancestor->kind === NodeKind::ArrowFunction) {
                return null;
            }

            if ($ancestor->kind !== NodeKind::Method && $ancestor->kind !== NodeKind::Function) {
                continue;
            }

            foreach ($file->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                    return trim($file->getText($child));
                }
            }
        }

        return null;
    }

    /**
     * The method declarations of a class-like body, in source order.
     *
     * Walked rather than read off one level, because a class-like's members sit inside its body node. This is
     * php-parser's `$classLike->getMethods()`, so it is the methods *written here* — not the ones a trait brings
     * in, and not the inherited ones a reflection lookup would add.
     *
     * @return list<Part>
     */
    public static function classMethods(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        $walk = function (Node $parent, int $depth) use (&$walk, $context, &$out): void {
            foreach ($context->source->getChildren($parent) as $child) {
                if ($child->kind === NodeKind::Method) {
                    $out[] = Tree::part($context, $child);

                    continue;
                }

                if ($depth < self::MEMBER_DEPTH) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($node, 0);

        return $out;
    }

    /** Whether a class-like declaration is written `abstract`, which is a modifier on it. */
    public static function declarationIsAbstract(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return false;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::Modifier && strtolower(trim($context->source->getText($child))) === 'abstract') {
                return true;
            }
        }

        return false;
    }

    /**
     * One method declaration of a class-like body, by name, or null when it declares none.
     *
     * php-parser's `ClassLike::getMethod()`, which a rule uses to reach a method it learned the name of at
     * analysis time — a data provider named in a docblock. Case insensitive, as PHP method names are.
     */
    public static function methodNamed(NodeAnalysisContext $context, Part|Node|null $classLike, ?string $name): ?Part
    {
        if ($name === null) {
            return null;
        }

        foreach (self::classMethods($context, $classLike) as $method) {
            if (strcasecmp((string) Members::methodName($method), $name) === 0) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The property declarations of a class-like body.
     *
     * @return list<Part>
     */
    public static function classProperties(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $member) {
            if ($member->kind !== NodeKind::ClassLikeMember) {
                continue;
            }

            foreach ($context->source->getChildren($member) as $child) {
                if (in_array($child->kind->value, ['Property', 'PlainProperty', 'HookedProperty'], true)) {
                    $out[] = Tree::part($context, $child);
                }
            }
        }

        return $out;
    }

    /** The enclosing class-like declaration's name, or null at top level. */
    public static function enclosingClassName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        [$file, $located] = Tree::locate($context, $node);

        // The node itself counts. A rule hooked on the class declaration is handed that declaration, so
        // walking only ancestors finds the *enclosing* class and returns null at top level, which made
        // every class-level name test silently fail.
        foreach ([$located, ...$file->getAncestors($located)] as $ancestor) {
            // Compared by backed value, not by case or by name. `NodeKind::Class` does not reference
            // the case at all, because PHP special-cases `::class` and silently yields the class-name
            // string, so every comparison against it is true and this method always returned null. The
            // case is spelled `Class_` while its value stays `Class`, so the value is the stable thing.
            if (! in_array($ancestor->kind->value, Tree::CLASS_LIKE_KINDS, true)) {
                continue;
            }

            foreach ($file->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                    $resolved = $file->getResolvedName($child);

                    return $resolved instanceof ResolvedName ? $resolved->name : trim($file->getText($child));
                }
            }
        }

        return null;
    }

    public static function isInClass(NodeAnalysisContext $context, Part|Node|null $node): bool
    {
        return self::enclosingClassName($context, $node) !== null;
    }

    /**
     * Whether the enclosing class is, or descends from, `$name`.
     *
     * `getClassAncestors()` answers in lowercase, which silently disabled every parent exclusion in an
     * earlier port until a probe printed the values, so the comparison is case insensitive here.
     */
    public static function enclosingClassIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        $className = self::enclosingClassName($context, $node);
        if ($className === null) {
            return false;
        }

        if (strcasecmp($className, $name) === 0) {
            return true;
        }

        foreach ($context->codebase->getClassAncestors($className) as $ancestor) {
            if (strcasecmp($ancestor, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** The class-declaration hook's `metadata_is`, which asks the same question. */
    public static function metadataIs(NodeAnalysisContext $context, Part|Node|null $node, string $name): bool
    {
        return self::enclosingClassIs($context, $node, $name);
    }
}
