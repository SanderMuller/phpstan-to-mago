<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * Whether a consumer silenced a finding in its own source with a phpstan-ignore comment.
 *
 * The same argument the corpus differential's cleared baseline rests on, one level down: a silenced violation
 * is a real finding the original *would* report, and PHPStan simply does not print it. Counting one as
 * "only-port" says the port is too wide when both engines in fact agreed — and that reading matters here,
 * because a differential exists to catch a port being *narrower*, which every false only-port entry buries.
 *
 * Measured rather than supposed: seven `noUnsafeRequestData` sites on a real project read as port-only
 * disagreements until this existed, and every one of them was a suppression the consumer had written.
 *
 * Nothing in a port can honour one of these — Mago has its own suppression syntax — so a consumer switching
 * engines rewrites them. That is a migration note, not a defect in either tool.
 *
 * A class of its own rather than a method on the differential, because the differential is already at its
 * complexity limit and this is a separate question: "what does the consumer's source say about this line",
 * not "what did the two engines report".
 */
final readonly class Suppressions
{
    /**
     * How many lines above a finding a suppression may sit.
     *
     * A trailing comment is on the line itself; a `/** … *\/` docblock and a next-line annotation sit above
     * it, and a multi-line docblock puts the annotation one line further up again.
     */
    private const int LOOKBEHIND = 2;

    public function __construct(private string $root) {}

    /**
     * Sites split into real disagreements and ones the consumer silenced in its own source.
     *
     * @param list<string> $sites
     *
     * @return array{list<string>, list<string>}
     */
    public function split(array $sites, string $identifier): array
    {
        $disagreements = [];
        $silenced = [];
        foreach ($sites as $site) {
            if ($this->silences($site, $identifier)) {
                $silenced[] = $site;
            } else {
                $disagreements[] = $site;
            }
        }

        return [$disagreements, $silenced];
    }

    /**
     * Whether the source at `file:line` silences `$identifier`.
     *
     * A named identifier has to match, so a suppression for something else does not hide a real
     * disagreement. A bare next-line annotation silences whatever follows it and so matches anything, which
     * is exactly what it does in PHPStan.
     */
    public function silences(string $site, string $identifier): bool
    {
        $lines = $this->linesAbove($site);

        foreach ($lines as $offset => $text) {
            // The `-line` form names nothing and silences the line it sits on, which is what the suffix means.
            // Missing it read three sites on Shopware as port-only where PHPStan reports them too and the
            // consumer had written the annotation in a trailing block comment after the call — the
            // identifier-matching branch below cannot see those, because there is no identifier to match.
            //
            // Spelled through {@see annotation()} here as everywhere else in this file: writing it out made
            // PHPStan read this comment as a real annotation on this line, and the run failed with
            // `ignore.unmatchedLine` — inside the code that exists to understand those annotations.
            if ($offset === 0 && str_contains($text, $this->annotation() . '-line')) {
                return true;
            }

            if ($offset > 0 && $this->isBareNextLine($text)) {
                return true;
            }

            if (str_contains($text, $this->annotation()) && str_contains($text, $identifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The finding's own line first, then the lines above it, as far back as a suppression may sit.
     *
     * @return array<int, string> keyed by how far above the finding each line is
     */
    private function linesAbove(string $site): array
    {
        $separator = strrpos($site, ':');
        if ($separator === false) {
            return [];
        }

        $path = $this->root . '/' . substr($site, 0, $separator);
        $line = (int) substr($site, $separator + 1);
        if ($line < 1 || ! is_file($path)) {
            return [];
        }

        $source = file($path, FILE_IGNORE_NEW_LINES);
        if ($source === false) {
            return [];
        }

        $lines = [];
        for ($offset = 0; $offset <= self::LOOKBEHIND; ++$offset) {
            $text = $source[$line - 1 - $offset] ?? null;
            if ($text !== null) {
                $lines[$offset] = $text;
            }
        }

        return $lines;
    }

    /** A next-line annotation naming nothing, which silences whatever follows regardless of identifier. */
    private function isBareNextLine(string $text): bool
    {
        return str_contains($text, $this->annotation() . '-next-line') && ! str_contains($text, '.');
    }

    /**
     * The annotation, assembled rather than written.
     *
     * Spelling it out in this file would make PHPStan read it as an annotation on *this* code — it did, and
     * the run failed with a parse error inside a docblock explaining the feature.
     */
    private function annotation(): string
    {
        return '@' . 'phpstan-ignore';
    }
}
