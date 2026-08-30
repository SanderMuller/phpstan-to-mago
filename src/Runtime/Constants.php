<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ConstantMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * The constants a class-like declares, and what each one is called and worth.
 *
 * Two levels, like properties: one `const` statement holds one or more items, and a rule asking about a
 * name is asking about an item rather than about the statement around it.
 */
final class Constants
{
    /**
     * The codebase metadata for a constant *read*, resolving the way PHP does.
     *
     * `getResolvedName()` on a `ConstantAccess` answers the namespace-qualified name, even for a global
     * constant: inside `namespace Dep`, `PHP_EOL` resolves to `Dep\PHP_EOL`. That is PHP's own rule — an
     * unqualified constant is looked for in the current namespace first and falls back to the global one —
     * and a lookup that stopped at the resolved name would answer null for every built-in constant read
     * inside a namespace, which is all real code. `FetchingDeprecatedConstRule` is *about* built-in
     * constants, so it would have emitted and then reported nothing.
     *
     * Measured rather than reasoned: probed on `PHP_EOL`, `FILTER_SANITIZE_STRING` and a namespaced
     * `MY_OWN`, all three resolve prefixed, and the codebase holds the first two only under their bare
     * names.
     */
    public static function constantMetadata(NodeAnalysisContext $context, Part|Node|null $subject): ?ConstantMetadata
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        [$file, $located] = Tree::locate($context, $node);
        $resolved = $file->getResolvedName($located);

        $candidates = [];
        if ($resolved instanceof ResolvedName) {
            $candidates[] = $resolved->name;
        }

        $candidates[] = trim($file->getText($located));

        foreach ($candidates as $candidate) {
            $metadata = $context->codebase->getConstant(ltrim($candidate, '\\'));
            if ($metadata instanceof ConstantMetadata) {
                return $metadata;
            }
        }

        return null;
    }

    /** Whether the codebase knows the constant this node reads. PHPStan's `hasConstant()`. */
    public static function constantExists(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return self::constantMetadata($context, $subject) instanceof ConstantMetadata;
    }

    /** Whether the constant this node reads carries a deprecation. */
    public static function constantIsDeprecated(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return self::constantMetadata($context, $subject)?->flags->contains(MetadataFlags::DEPRECATED) === true;
    }

    /** The constant's name as the codebase holds it, for a message that interpolates it. */
    public static function constantName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        return self::constantMetadata($context, $subject)?->name;
    }

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
