<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * The constants a class-like declares, and what each one is called and worth.
 *
 * Two levels, like properties: one `const` statement holds one or more items, and a rule asking about a
 * name is asking about an item rather than about the statement around it.
 */
final class Constants
{
    /**
     * The items of a constant declaration: `const A = 1, B = 2;` has two.
     *
     * @return list<Part>
     */
    public static function constantItems(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ClassLikeConstantItem || $child->kind === NodeKind::ConstantItem) {
                $out[] = Tree::part($context, $child);
            }
        }

        return $out;
    }

    /**
     * The constant *declarations* a class-like holds — `const A = 1, B = 2;` is one of them.
     *
     * Probed: a declaration sits under a `ClassLikeMember` wrapper rather than directly under the class, the
     * same way methods and properties do. `getConstants()` in php-parser answers with the statements, which is
     * what a rule then reads the items out of.
     *
     * @return list<Part>
     */
    public static function constantDeclarations(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $child) {
            foreach ($context->source->getChildren($child) as $member) {
                if ($member->kind === NodeKind::ClassLikeConstant) {
                    $out[] = Tree::part($context, $member);
                }
            }
        }

        return $out;
    }

    /**
     * A constant item's value, as the node it is written as.
     *
     * Probed: an item holds its name as a `LocalIdentifier` and its value wrapped in an `Expression`, which is
     * unwrapped here the way {@see nthExpression} unwraps it — so what comes back is the value a rule asks the
     * type of, not the wrapper.
     */
    public static function constantItemValue(NodeAnalysisContext $context, ?Part $item): ?Part
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ($item->children() as $child) {
            if ($child->kind !== NodeKind::Expression) {
                continue;
            }

            return $child->children()[0] ?? $child;
        }

        return null;
    }

    /** A constant item's name, without its value. */
    public static function constantItemName(?Part $item): ?string
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ($item->children() as $child) {
            if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                return $child->text;
            }
        }

        return null;
    }
}
