<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

/**
 * String and array helpers that ask nothing of the analysed file.
 *
 * The first cut out of {@see Support}, and the cleanest: nothing here reads a node, a type or the codebase,
 * nothing here calls anything else in the runtime, and nothing else in the runtime calls it. Pure functions
 * over values a rule already holds.
 */
final class Text
{
    /**
     * Whether a list of names holds one, folding case.
     *
     * The list comes from metadata, which lowercases; the name comes from configuration or from the analysed
     * source, which does not. A strict comparison between the two is the silent-miss shape.
     *
     * @param list<string> $names
     */
    public static function namesContain(array $names, ?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        foreach ($names as $candidate) {
            if (strcasecmp(ltrim($candidate, '\\'), ltrim($name, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values a list holds more than once, each named once.
     *
     * Built on `array_count_values()` rather than around it, so the key coercion is the same: that function
     * turns a numeric-string value into an integer key, and a rule that goes on to print the duplicates prints
     * whatever it produced. Reimplementing the count would have quietly changed that.
     *
     * @param list<string> $values
     *
     * @return list<int|string>
     */
    public static function repeatedValues(array $values): array
    {
        $repeated = [];
        foreach (array_count_values($values) as $value => $count) {
            if ($count <= 1) {
                continue;
            }

            $repeated[] = $value;
        }

        return $repeated;
    }

    /**
     * A configured map with keys that differ only in case collapsed to one entry, the last winning.
     *
     * This stands in for a rewriting the rule did and the plugin does not. The original built its map keyed by
     * each name's *declared* spelling, so two configured keys naming the same trait in different cases became
     * one entry and the later assignment won. Carrying the configured map as written kept both, and a
     * case-insensitive match then found both — reporting the same finding twice.
     *
     * Only the keys collapse. The values are compared case-insensitively wherever they are used, so folding
     * them would change nothing but the spelling a message prints.
     *
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    public static function foldedKeys(array $map): array
    {
        $folded = [];
        foreach ($map as $key => $value) {
            $folded[strtolower(ltrim((string) $key, '\\'))] = [$key, $value];
        }

        $collapsed = [];
        foreach ($folded as [$key, $value]) {
            $collapsed[$key] = $value;
        }

        return $collapsed;
    }

    /** `->toLowerString()` on a name, which rules use so a comparison ignores how the name was written. */
    public static function lowerBytes(?string $text): ?string
    {
        return $text === null ? null : strtolower($text);
    }

    /** The other half of `lowerBytes()`, for a rule that folds the other way. */
    public static function upperBytes(?string $text): ?string
    {
        return $text === null ? null : strtoupper($text);
    }

    /**
     * Whether a lookup table the plugin built holds a key, where the key may not have resolved.
     *
     * `isset($table[$key])` with a null key is not an error in PHP — it reads `$table['']` — so the emitted
     * form worked and analysing the generated plugins is what flagged it. Worth a helper rather than a cast:
     * a rule's table is keyed by names it wrote, and `''` is a name it *could* write, so coercing an
     * unresolved key into it would answer yes to a question nobody asked.
     *
     * @param array<string, mixed> $table
     */
    public static function lookupHas(array $table, ?string $key): bool
    {
        return $key !== null && isset($table[$key]);
    }

    /**
     * `array_any()` is PHP 8.4, and the generated rules should run on 8.1.
     *
     * Generic, because the body is: the emitter hands it a list of names from a configured list and a list of
     * `Part`s from a declaration's items, and annotating one of those made every emission of the other a type
     * error. Nothing noticed until the generated plugins were analysed — the gate that checks a helper *exists*
     * cannot see what it is handed.
     *
     * @template TItem
     *
     * @param list<TItem> $items
     * @param callable(TItem): bool $predicate
     */
    public static function anyOf(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $items
     * @param callable(string): bool $predicate
     */
    public static function allOf(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if (! $predicate($item)) {
                return false;
            }
        }

        return true;
    }

    public static function bytesContain(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_contains($haystack, $needle);
    }

    public static function bytesEndWith(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_ends_with($haystack, $needle);
    }

    public static function bytesStartWith(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_starts_with($haystack, $needle);
    }

    /**
     * Whether a string value is one of a set.
     *
     * The counterpart of {@see selectorIsOneOf} for a value that is already a string rather than a node: a
     * helper's string parameter, or the enclosing namespace. Compared case sensitively, because the sets it
     * is asked about — function names in a constant table — are written the way PHP compares them.
     *
     * @param list<string> $values
     */
    public static function bytesIsOneOf(?string $subject, array $values): bool
    {
        return $subject !== null && in_array($subject, $values, true);
    }

    /**
     * Whether a pattern matches, which is `Strings::match(..) !== null` and `preg_match(..) === 1`.
     *
     * Nette's helper hands back the capture array or null; with two arguments and its defaults that is
     * `preg_match()`'s own answer, so the two spellings reduce to one question here. A null subject cannot
     * match anything, which is what the guards in front of it already assume.
     */
    public static function matchesPattern(?string $subject, string $pattern): bool
    {
        return $subject !== null && preg_match($pattern, $subject) === 1;
    }

    /**
     * A string split on a pattern, dropping the empty pieces — `preg_split(.., -1, PREG_SPLIT_NO_EMPTY)`.
     *
     * A `list<string>` rather than `array|false`, because the caller has no use for the failure: `preg_split`
     * answers false only for a pattern it cannot compile, and the pattern reaches here as a literal the
     * transpiler read out of the rule. So a rule's own `=== false` guard is folded away where it is written,
     * and this hands back the empty list for the shapes that have nothing to split — a null subject, or a
     * subject that is entirely separators.
     *
     * @return list<string>
     */
    public static function splitByPattern(?string $subject, string $pattern): array
    {
        if ($subject === null) {
            return [];
        }

        // Already a list without `PREG_SPLIT_OFFSET_CAPTURE`, so nothing to reindex.
        $parts = preg_split($pattern, $subject, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /** Whether a name is written entirely in upper case, as a constant convention check. */
    public static function isUppercase(?string $value): bool
    {
        return $value !== null && $value === strtoupper($value);
    }

    /**
     * One named group of a match, or null when the pattern did not match or the group caught nothing.
     *
     * An empty capture reads as null here. `preg_match()` fills an unmatched optional group with `''`, and a
     * rule's `isset($matches['x'])` cannot tell the two apart — so treating `''` as "not caught" matches what
     * the rule means. No pattern in the corpus has an optional group that can match empty.
     */
    public static function captured(string $pattern, ?string $subject, string $group): ?string
    {
        if ($subject === null || preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        $value = $matches[$group] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The lines of a docblock that carry a tag, which is what `getTagsByName()` hands a rule.
     *
     * Matched as a tag rather than as a substring: `@enum` must not be found in `@enumerate`, and a rule
     * asking for one tag and getting another is the kind of wrong answer that reads as right. The tag has to
     * be followed by whitespace, a parenthesis, or the end of the line.
     *
     * @return list<string>
     */
    public static function docblockTags(?string $docblock, string $tag): array
    {
        if ($docblock === null) {
            return [];
        }

        $found = [];
        foreach (explode("\n", $docblock) as $line) {
            if (preg_match('/(?<![\\w@])' . preg_quote($tag, '/') . '(?![\\w-])/', $line) === 1) {
                $found[] = trim($line);
            }
        }

        return $found;
    }

    /** Two written names compared the way PHP compares them: case-insensitively, and null matching nothing. */
    public static function nameIs(?string $written, string $name): bool
    {
        return $written !== null && strcasecmp($written, $name) === 0;
    }

    /**
     * Whether a path a rule built exists on disk.
     *
     * A plugin is PHP, so it can ask the filesystem the same question the rule asks. Null-tolerant because the
     * path is built from a string the rule read off a node, and a node that held no string yields none.
     */
    public static function pathExists(?string $path): bool
    {
        return $path !== null && file_exists($path);
    }
}
