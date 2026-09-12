<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;
use SplFileInfo;

/**
 * PHPStan over the generated plugins, which is the one output nothing type-checked.
 *
 * `phpstan.neon.dist` excludes `tests/Fixtures/expected` on purpose — reformatting reviewed output would change
 * what the snapshot test compares against — and excluding it from *analysis* came along with that for free. It
 * was never the intent. The emitted plugin is what this project calls the contract, and
 * {@see TranspilesToPhpTest} checks that it parses, holds no Rust, and calls only helpers that exist and are
 * public. None of that sees a helper being handed the wrong type.
 *
 * The first run found four of those, in two committed snapshots: a `string` where `Support::` declares a `Part`
 * and a `list<Part>` where it declares a `list<string>`, on one line each. The cause was one wrong annotation
 * on `Support::anyOf()`, which is generic in fact and was not in writing. It also found an emitted guard
 * repeated verbatim, a redundant conjunct in another, an untyped property and a lookup keyed by a possibly-null
 * name — all now fixed at the emitter rather than suppressed.
 *
 * A separate config rather than a path added to the main one: generated code should not answer for this
 * project's own house style. Five of the seventeen first findings were `type_coverage` and cognitive
 * complexity, which would have buried the four that mattered.
 */
#[Group('engine')]
final class AnalysesTheEmittedPluginsTest extends TestCase
{
    /** Everything under a directory, so no stale result survives to answer for a file that changed. */
    private function clear(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
    }

    public function test_the_generated_plugins_type_check(): void
    {
        $root = dirname(__DIR__, 2);

        // Cleared every run, and *recursively* — the two go together. PHPStan's result cache keys on the
        // analysed files and the configuration, and `src/Runtime/Support.php` is neither: it is a dependency of
        // them. Fixing an annotation there and re-running reported the same four errors until the cache went.
        //
        // A first version deleted only the files directly under the cache directory, which left the nested ones
        // and made this test pass while an annotation was mutated back to the broken one. The mutation check is
        // what caught that, on the gate itself.
        $this->clear($root . '/.cache/phpstan-emitted');

        $process = proc_open(
            [
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '--configuration=phpstan-emitted.neon',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            Subprocess::environment(),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start PHPStan');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Decoded here rather than through `PhpstanReport`, which exists to filter a differential down to one
        // rule's identifier and drops an empty prefix — so asking it for *everything* asked it for nothing, and
        // this test passed while two annotations were mutated back to broken. Found by mutation-checking the
        // gate, which is the only reason it is not still green and useless.
        $output = $stdout === '' ? $stderr : $stdout;
        $start = strpos($output, '{');
        /** @var array{totals?: array{file_errors?: int}, files?: array<string, array{messages?: list<array{line?: int, identifier?: string, message?: string}>}>, errors?: list<string>}|null $decoded */
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (! is_array($decoded) || ! isset($decoded['files'])) {
            throw new RuntimeException("PHPStan produced no JSON report for the generated plugins:\n" . $output);
        }

        $findings = [];
        foreach ($decoded['files'] as $path => $file) {
            foreach ($file['messages'] ?? [] as $message) {
                $findings[] = sprintf(
                    '%s:%d  [%s] %s',
                    basename($path),
                    $message['line'] ?? 0,
                    $message['identifier'] ?? '?',
                    $message['message'] ?? '',
                );
            }
        }

        // A whole-run error — a bad config, an unreadable path — is not a finding and must not read as one.
        $this->assertSame([], $decoded['errors'] ?? [], 'PHPStan did not get as far as analysing.');

        $this->assertSame(
            [],
            $findings,
            "PHPStan reports on the generated plugins, which means the transpiler emitted it:\n"
            . 'reproduce with `vendor/bin/phpstan analyse --configuration=phpstan-emitted.neon`.',
        );
    }
}
