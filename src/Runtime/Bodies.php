<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * What a class-like body holds, read from the tree.
 *
 * Four readers of one layer, split out of {@see Declares} when the two of them together passed the complexity
 * limit. They are a group by the call graph rather than by subject: each walks a class-like's children looking
 * for members, and the only thing any of them calls out to is {@see Members::methodName()}.
 *
 * The layer is measured in `internal/probe-class-members.php`. Every member of a class, a trait or an enum is
 * wrapped in one `ClassLikeMember` holding exactly one declaration, which is why {@see self::classMembers()}
 * unwraps a single level and the kind-specific readers look one deeper.
 */
final class Bodies
{
    /** How far below a class-like to look for its members: body, then member list. */
    private const int MEMBER_DEPTH = 3;

    /**
     * Every member a class-like body writes, in source order, which is php-parser's `$classLike->stmts`.
     *
     * php-parser hands a rule one mixed list — methods, constants, properties, trait uses, enum cases — and a
     * rule walking it branches on the kind of each. Mago wraps every member in a `ClassLikeMember` holding
     * exactly one declaration, measured in `internal/probe-class-members.php` across a class, a trait and an
     * enum, so unwrapping one level gives the same list in the same order.
     *
     * Every member is returned rather than the kinds a caller wants, because the caller's own predicate is
     * what decides: `NoProtectedClassStmtRule` skips a trait use through the same `continue` the original
     * writes, and filtering here would make the port skip it for a different reason.
     *
     * @return list<Part>
     */
    public static function classMembers(NodeAnalysisContext $context, Part|Node|null $subject): array
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

            foreach ($context->source->getChildren($member) as $declaration) {
                $out[] = Tree::part($context, $declaration);
            }
        }

        return $out;
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
}
