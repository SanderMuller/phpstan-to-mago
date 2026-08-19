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
     * @return array<string, list<string>>
     *
     * @throws RuntimeException when the output is not a shape this understands, which means PHPStan did not
     *                          run and there is nothing to compare
     */
    public static function findings(string $output, string $identifier, string $context = 'the rule'): array
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

            return self::sorted(self::collect($byFile, $identifier));
        }

        // Some environments wrap PHPStan's output in a reporting envelope. Accepted rather than fought,
        // because the alternative is a differential that only runs on one machine.
        if (isset($decoded['error_details']) && is_array($decoded['error_details'])) {
            /** @var array<string, list<array{line?: int, identifier?: string, message?: string}>> $details */
            $details = $decoded['error_details'];

            return self::sorted(self::collect($details, $identifier));
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
    private static function collect(array $byFile, string $identifier): array
    {
        $findings = [];
        foreach ($byFile as $path => $messages) {
            foreach ($messages as $message) {
                if (! str_starts_with((string) ($message['identifier'] ?? ''), $identifier)) {
                    continue;
                }

                $findings[basename($path)][] = ($message['line'] ?? 0) . ': ' . ($message['message'] ?? '');
            }
        }

        return $findings;
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
            sort($lines);
            $findings[$file] = $lines;
        }

        ksort($findings);

        return $findings;
    }
}
