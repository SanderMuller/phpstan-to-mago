<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * A written type hint, and what shape it is.
 *
 * A union, an intersection, a nullable or a plain name — read from the hint node rather than from an
 * inferred type, because a rule asking about a hint is asking what the author wrote.
 */
final class Hints
{
    public static function hintIsUnion(?Part $hint): bool
    {
        return self::hintShape($hint) === NodeKind::UnionHint;
    }

    public static function hintIsIntersection(?Part $hint): bool
    {
        return self::hintShape($hint) === NodeKind::IntersectionHint;
    }

    private static function hintShape(?Part $hint): ?NodeKind
    {
        return $hint?->firstChild()?->kind;
    }

    /**
     * The members of a union or intersection hint, or the hint itself when it is neither.
     *
     * @return list<Part>
     */
    public static function hintParts(?Part $hint): array
    {
        if (! $hint instanceof Part) {
            return [];
        }

        $shape = $hint->firstChild();
        if (! $shape instanceof Part || ($shape->kind !== NodeKind::UnionHint && $shape->kind !== NodeKind::IntersectionHint)) {
            return [$hint];
        }

        $out = [];
        foreach ($shape->children() as $child) {
            if ($child->kind === NodeKind::Hint) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * A hint that is a class-like *name*, which is what php-parser's `$param->type instanceof Name` asks.
     *
     * Not "a hint that is present and not a union" — that was the earlier reading, and it is wider than the
     * question: php-parser gives an `Identifier` for a builtin, so a rule asking `instanceof Name` is
     * distinguishing a class from `int`. Nothing emitted depended on the wider version.
     *
     * Mago's discriminator, probed across ten written forms:
     *
     * | written                          | `Hint` child      | resolves |
     * |----------------------------------|-------------------|----------|
     * | `Widget`, `\Root\Deep`, an import | `Identifier`      | to a FQN |
     * | `int`, `iterable`, `mixed`       | `LocalIdentifier` | no       |
     * | `array`, `callable`              | `Keyword`         | no       |
     * | `self`, `static`, `parent`       | `Keyword`         | no       |
     * | `?Widget`                        | `NullableHint`    | no       |
     * | `string\|int`                    | `UnionHint`       | no       |
     *
     * So an `Identifier` child is a class-like name. The `Keyword` row splits, because php-parser does: `self`,
     * `static` and `parent` are `Name` there while `array` and `callable` are `Identifier`, and a vocabulary that
     * answered no for `self` would be *narrower* than the rule — the direction that makes a port silently miss.
     */
    public static function hintIsName(?Part $hint): bool
    {
        if (! $hint instanceof Part) {
            return false;
        }

        $inner = $hint->firstChild();
        if (! $inner instanceof Part) {
            return false;
        }

        if ($inner->kind === NodeKind::Identifier) {
            return true;
        }

        return $inner->kind === NodeKind::Keyword
            && in_array(strtolower(trim($inner->text)), ['self', 'static', 'parent'], true);
    }

    /** A hint's written name, resolved through the file's imports where possible. */
    public static function hintName(NodeAnalysisContext $context, ?Part $hint): ?string
    {
        if (! $hint instanceof Part) {
            return null;
        }

        $resolved = $context->source->getResolvedName($hint->node);

        return $resolved instanceof ResolvedName ? $resolved->name : $hint->text;
    }

    public static function hintNameIs(NodeAnalysisContext $context, ?Part $hint, string $name): bool
    {
        $written = self::hintName($context, $hint);

        return $written !== null && strcasecmp($written, $name) === 0;
    }
}
