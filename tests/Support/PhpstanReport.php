<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use RuntimeException;

/**
 * PHPStan's findings, read out of whichever JSON shape its output arrived in.
 *
 * Its own class because the parsing is the load-bearing part of the differential and the easiest thing to
 * get quietly wrong. An earlier version returned "no findings" for a run that had *failed to start*, which
 * made the comparison pass for five rules that reported nothing — both sides silent, agreeing on zero. So
 * anything unrecognised throws here rather than reading as an empty result.
 */
final readonly class PhpstanReport
{
    /**
     * The findings for one rule, keyed by file base name, each entry `line: message`.
     *
     * `$identifier` is matched as a prefix, because a rule that classifies what it found reports under a
     * computed code and the leading literal is what every code it can report has in common. A list matches any
     * of them, which is what a merged rule needs: it reports under one identifier per check.
     *
     * `$relativeTo` decides what a file is called. A per-rule gate compares base names, because its two
     * sandboxes put the same example at different absolute paths. A corpus differential cannot: two files can
     * share a base name, and collapsing them makes one rule's finding look like another's. Passing the corpus
     * root keys by path relative to it, which is what the other engine's report is normalised to.
     *
     *
     *
     * @param string|list<string> $identifier one prefix, or every prefix a merged rule reports under
     * @return array<string, list<string>>
     * @throws RuntimeException when the output is not a shape this understands, which means PHPStan did not
     *                          run and there is nothing to compare
     */
    public static function findings(string $output, string|array $identifier, string $context = 'the rule', ?string $relativeTo = null): array
    {
        $start = strpos($output, '{');
        /** @var array<string, mixed>|null $decoded */
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("PHPStan produced no JSON for {$context}:\n" . $output);
        }

        self::refuseUnanalysedFiles($decoded, $context);

        // PHPStan's own `--error-format=json`.
        if (isset($decoded['files']) && is_array($decoded['files'])) {
            /** @var array<string, array{messages?: list<array{line?: int, identifier?: string, message?: string}>}> $files */
            $files = $decoded['files'];
            $byFile = [];
            foreach ($files as $path => $file) {
                $byFile[$path] = $file['messages'] ?? [];
            }

            return self::sorted(self::collect($byFile, $identifier, $relativeTo));
        }

        // A wrapping envelope may *cap* how many errors it lists, and one measured here declared 1160 while
        // shipping 30 and setting `truncated: true`. Read as complete, that turns every port finding past the
        // cap into a phantom disagreement and hides every original-only finding past it — silently, and in
        // both directions at once. Refused, because a differential built on a truncated original measures
        // nothing.
        //
        // The flag *and* the arithmetic, because the flag is the envelope's own courtesy and the count is a
        // fact about what arrived. An envelope that caps without saying so is the same defect and has to fail
        // the same way.
        $declared = $decoded['errors'] ?? null;
        $carried = self::countMessages($decoded['error_details'] ?? []);
        if (($decoded['truncated'] ?? false) === true || (is_int($declared) && $declared > $carried)) {
            throw new RuntimeException(sprintf(
                "PHPStan's output was truncated for %s — it declares %s errors and lists %d, so the original "
                . "side is incomplete and nothing can be compared against it. Make the run emit PHPStan's own "
                . '`--error-format=json`, which does not cap.',
                $context,
                var_export($declared ?? 'an unknown number of', true),
                $carried,
            ));
        }

        // Some environments wrap PHPStan's output in a reporting envelope. Accepted rather than fought,
        // because the alternative is a differential that only runs on one machine.
        if (isset($decoded['error_details']) && is_array($decoded['error_details'])) {
            /** @var array<string, list<array{line?: int, identifier?: string, message?: string}>> $details */
            $details = $decoded['error_details'];

            return self::sorted(self::collect($details, $identifier, $relativeTo));
        }

        // A clean run genuinely found nothing, and says so rather than leaving it to be inferred.
        if (self::isCleanRun($decoded)) {
            return [];
        }

        throw new RuntimeException("PHPStan did not run for {$context}, so there is nothing to compare:\n" . $output);
    }

    /**
     * Refuses a run where PHPStan could not analyse a file at all.
     *
     * A `phpstan.parse` error is not a finding: it says PHPStan stopped at that file, so **no rule ran in
     * it**. The port has its own parser and analyses the file anyway, which turns every finding it makes
     * there into a phantom disagreement — silently, and only in the port's direction.
     *
     * Measured rather than imagined. Pointing the differential at `phpstan-src`'s rule tests gave
     * `agree 0, only-original 0, only-port 313`, a table that reads like a catastrophic divergence. PHPStan
     * had reported three `phpstan.parse` errors on fixture files that are invalid PHP *on purpose* —
     * `void cannot be used as a parameter type` — and analysed nothing. Without this guard that run printed
     * counts, and counts from a run where one side never looked are worse than no counts.
     *
     * Named, not summarised: the fix is to exclude the file, and a reader needs to know which.
     *
     * @param array<string, mixed> $decoded
     */
    private static function refuseUnanalysedFiles(array $decoded, string $context): void
    {
        /** @var array<string, mixed> $sources */
        $sources = [];
        foreach (['files', 'error_details'] as $key) {
            $section = $decoded[$key] ?? null;
            if (is_array($section)) {
                $sources = $section;

                break;
            }
        }

        $unanalysed = [];
        foreach ($sources as $path => $entry) {
            /** @var list<array{identifier?: string}> $messages */
            $messages = is_array($entry) ? ($entry['messages'] ?? $entry) : [];
            foreach ($messages as $message) {
                if (is_array($message) && str_starts_with((string) ($message['identifier'] ?? ''), 'phpstan.parse')) {
                    $unanalysed[] = (string) $path;

                    break;
                }
            }
        }

        if ($unanalysed !== []) {
            throw new RuntimeException(sprintf(
                "PHPStan could not parse %d file(s) for %s, so no rule ran in them and the port's findings "
                . 'there would be phantom disagreements. Exclude them from the corpus, or measure a corpus '
                . 'that parses:
  %s',
                count($unanalysed),
                $context,
                implode('
  ', $unanalysed),
            ));
        }
    }

    /**
     * How many messages an envelope actually carries, for comparing against the total it declares.
     */
    private static function countMessages(mixed $details): int
    {
        if (! is_array($details)) {
            return 0;
        }

        $count = 0;
        foreach ($details as $messages) {
            $count += is_array($messages) ? count($messages) : 0;
        }

        return $count;
    }

    /**
     * The messages of the rule's own identifiers, as `line: message` keyed by file base name.
     *
     * @param array<string, list<array{line?: int, identifier?: string, message?: string}>> $byFile
     * @param string|list<string>                                                             $identifier
     *
     * @return array<string, list<string>>
     */
    private static function collect(array $byFile, string|array $identifier, ?string $relativeTo = null): array
    {
        // A merged rule reports under one identifier per check, so matching a single prefix would compare one
        // check and read the others' absence as agreement.
        $prefixes = array_filter(is_array($identifier) ? $identifier : [$identifier], static fn (string $prefix): bool => $prefix !== '');

        $findings = [];
        foreach ($byFile as $path => $messages) {
            foreach ($messages as $message) {
                $found = (string) ($message['identifier'] ?? '');
                $matched = false;
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($found, $prefix)) {
                        $matched = true;

                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }

                $findings[self::name($path, $relativeTo)][] = ($message['line'] ?? 0) . ': ' . ($message['message'] ?? '');
            }
        }

        return $findings;
    }

    /**
     * What to call the file a finding is in, with PHPStan's context suffix removed.
     *
     * PHPStan analyses a trait once per using class and names the file
     * `Concerns/EnumUtils.php (in context of class App\\Enums\\Ability)`. A trait used by 120 classes therefore
     * arrives as 120 findings on one line, where an engine that analyses each file once reports it once. Left
     * in, that difference reads as the port missing 477 sites — the largest disagreement in the first run of
     * this harness, and entirely an artefact of the two conventions.
     */
    private static function name(string $path, ?string $relativeTo): string
    {
        $context = strpos($path, ' (in context of ');
        if ($context !== false) {
            $path = substr($path, 0, $context);
        }

        if ($relativeTo === null) {
            return basename($path);
        }

        return str_starts_with($path, $relativeTo . '/') ? substr($path, strlen($relativeTo) + 1) : $path;
    }

    /**
     * Whether the output states, rather than implies, that the run finished and found nothing.
     *
     * @param array<string, mixed> $decoded
     */
    private static function isCleanRun(array $decoded): bool
    {
        if (($decoded['result'] ?? null) === 'passed') {
            return true;
        }

        $totals = $decoded['totals'] ?? null;

        return is_array($totals) && ($totals['file_errors'] ?? null) === 0;
    }

    /**
     * @param array<string, list<string>> $findings
     *
     * @return array<string, list<string>>
     */
    private static function sorted(array $findings): array
    {
        foreach ($findings as $file => $lines) {
            // One site, once. A trait analysed in the context of 120 using classes yields 120 identical
            // entries, and the comparison is over sites — so keeping them would only make one site read as
            // 120 and any engine that visits the file once read as missing 119 of them.
            $lines = array_values(array_unique($lines));
            sort($lines);
            $findings[$file] = $lines;
        }

        ksort($findings);

        return $findings;
    }
}
