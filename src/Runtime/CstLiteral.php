<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

/**
 * The value of a quoted string, decoded from a node's source text.
 *
 * `SourceFile::getLiteralString()` answers this, but returns null in an after-analysis pass: decoded literals
 * are a snapshot requirement and `FileAnalysisRequirement` has no case for them. Measured in
 * `internal/probe-after-hook-self-contained.php`, where the text was available and the decoded literal was
 * not.
 *
 * Its own class because two whole-project passes need the same answer — a validated field name and a facade
 * alias — and one decoder they share cannot drift apart.
 */
final class CstLiteral
{
    /**
     * The value of a plain quoted string, or null for anything else.
     *
     * Only the unambiguous shape is accepted: no backslash, no nested quote, no interpolation. A mis-decoded
     * value is worse than none in both callers — a field name that does not match the key that validates it,
     * or a class name that does not match the class it aliases — so ambiguity is unresolvable rather than
     * guessed at, and the caller treats it as "cannot prove".
     */
    public static function plainString(string $text): ?string
    {
        $text = trim($text);
        if (strlen($text) < 2 || str_contains($text, '\\')) {
            return null;
        }

        $quote = $text[0];
        if (($quote !== "'" && $quote !== '"') || substr($text, -1) !== $quote) {
            return null;
        }

        $inner = substr($text, 1, -1);
        if (str_contains($inner, $quote)) {
            return null;
        }

        return $quote === '"' && (str_contains($inner, '$') || str_contains($inner, '{')) ? null : $inner;
    }
}
