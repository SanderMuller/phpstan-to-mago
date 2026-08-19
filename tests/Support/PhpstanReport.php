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
     * computed code and the leading literal is what every code it can report has in common.
     *
     * `$relativeTo` decides what a file is called. A per-rule gate compares base names, because its two
     * sandboxes put the same example at different absolute paths. A corpus differential cannot: two files can
     * share a base name, and collapsing them makes one rule's finding look like another's. Passing the corpus
     * root keys by path relative to it, which is what the other engine's report is normalised to.
     *
     * @return array<string, list<string>>
     *
     * @throws RuntimeException when the output is not a shape this understands, which means PHPStan did not
     *                          run and there is nothing to compare
     */
    public static function findings(string $output, string $identifier, string $context = 'the rule', ?string $relativeTo = null): array
    {
        $start = strpos($output, '{');
        /** @var array<string, mixed>|null $decoded */
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("PHPStan produced no JSON for {$context}:\n" . $output);
        }

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
     * The messages of one identifier, as `line: message` keyed by file base name.
     *
     * @param array<string, list<array{line?: int, identifier?: string, message?: string}>> $byFile
     *
     * @return array<string, list<string>>
     */
    private static function collect(array $byFile, string $identifier, ?string $relativeTo = null): array
    {
        $findings = [];
        foreach ($byFile as $path => $messages) {
            foreach ($messages as $message) {
                if (! str_starts_with((string) ($message['identifier'] ?? ''), $identifier)) {
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
