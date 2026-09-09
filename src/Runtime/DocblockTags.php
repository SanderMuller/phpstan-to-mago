<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Sandermuller\PhpstanToMago\Tests\Unit\AnnotationTagsAreNotInheritedTest;

/**
 * The values of one annotation tag on a declaration, which is what `getTagsByName()` answers.
 *
 * PHPStan reaches these through `getResolvedPhpDoc()` and a parsed PHPDoc tree. A plugin has the docblock's
 * *text*, through {@see Support::docblockText()}, and that is enough for an arbitrary tag — **measured, not
 * assumed**: {@see AnnotationTagsAreNotInheritedTest} establishes that
 * an arbitrary tag is reported only on the declaration that writes it, so the narrower primitive answers the
 * same question. If that stops being true the test fails rather than the plugins going quiet.
 *
 * **The tag name matches exactly**, which is the whole reason this is not `str_contains`. `@coversNothing` and
 * `@coversDefaultClass` are not `@covers`, and PHPStan's `getTagsByName('@covers')` does not match them
 * either. The greedy-name mistake is the one `PhpUnitAnnotations` records from the other direction: its
 * `[a-zA-Z]+` captures the whole name so `@coversNothing` never enters its list.
 *
 * **One known divergence, stated rather than hidden.** A tag value is read to the end of its line. PHPStan's
 * PHPDoc parser continues a generic tag's value onto following lines until the next tag, so a covers value
 * wrapped over two lines reads as its first line here. No corpus rule and no example in this repository
 * writes one, and a wrapped class name is not valid input to the rules that consume these tags, so the
 * divergence is unreachable by anything that would report — but it is a divergence and not a limitation of
 * the tag being unavailable.
 */
final class DocblockTags
{
    /**
     * Every value written for `@$tag` on this declaration, in source order.
     *
     * @param string $tag without the at-sign
     * @return list<string>
     */
    public static function values(NodeAnalysisContext $context, Part|Node|null $declaration, string $tag): array
    {
        return self::in(Support::docblockText($context, $declaration), $tag);
    }

    /** How many times `@$tag` is written on this declaration. */
    public static function count(NodeAnalysisContext $context, Part|Node|null $declaration, string $tag): int
    {
        return count(self::values($context, $declaration, $tag));
    }

    /**
     * The first value written for `@$tag`, or null where the tag is absent.
     *
     * The shape `array_shift()` over the tag list produces in two of the rules that need this, without a list
     * local for the transpiler to model.
     */
    public static function first(NodeAnalysisContext $context, Part|Node|null $declaration, string $tag): ?string
    {
        return self::values($context, $declaration, $tag)[0] ?? null;
    }

    /**
     * The tag values in one docblock's text.
     *
     * Split out so the scan can be read and tested without a mago context around it, the way
     * `PhpUnitAnnotations::violations()` is.
     *
     * @return list<string>
     */
    public static function in(?string $docblock, string $tag): array
    {
        if ($docblock === null) {
            return [];
        }

        $lines = preg_split("/((\r?\n)|(\r\n?))/", $docblock);
        if ($lines === false) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            // The leading `*` of a docblock line, then the tag, then either whitespace or the end of the
            // line. `(?![a-zA-Z])` is what keeps `@coversDefaultClass` out of `@covers`.
            $matched = preg_match('/^\s*\*?\s*@' . preg_quote($tag, '/') . '(?![a-zA-Z])(?<value>.*)$/', $line, $matches);
            if ($matched !== 1) {
                continue;
            }

            $values[] = trim($matches['value']);
        }

        return $values;
    }
}
