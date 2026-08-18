<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * The good and bad examples a linter rule embeds in its metadata.
 *
 * Lives apart from the transpiler because it is the one part that reads the world rather than the rule:
 * it walks a directory of annotated PHP and picks the unit whose annotations name this rule. Passing in
 * whether the rule reads the file name, rather than looking at the emitted body, is what let it move.
 */
final readonly class ExampleReader
{
    public function __construct(private ?string $directory) {}

    /**
     * @return array{string, string} the good and bad example for this rule, blank when none is known
     */
    public function forRule(string $className, bool $ruleReadsTheFileName): array
    {
        $blank = "<?php\n";
        if ($this->directory === null) {
            return [$blank, $blank];
        }

        /** @var list<array{file: string, header: list<string>, lines: list<string>, open: int}> $units */
        $units = [];
        $paths = glob($this->directory . '/*.php');
        foreach ($paths === false ? [] : $paths as $path) {
            /** @var list<string> $lines */
            $read = file($path);
            $lines = $read === false ? [] : $read;
            /** @var list<string> $header */
            $header = [];
            $headerDepth = 0;
            $depth = 0;
            /** @var array{file: string, header: list<string>, lines: list<string>, open: int}|null $unit */
            $unit = null;
            foreach ($lines as $line) {
                $opens = substr_count($line, '{');
                $closes = substr_count($line, '}');
                if ($depth === 0 && $unit === null) {
                    // A top-level declaration starts here, or this is still the file header.
                    if (preg_match('/^\s*(final |abstract |readonly )*(class|interface|trait|enum|function|const) /', $line) === 1) {
                        // A docblock or comment run immediately above belongs to this declaration, not
                        // to the file, or it would be repeated on top of every later unit.
                        $own = [];
                        while ($header !== [] && trim(end($header)) !== '' && ! str_ends_with(rtrim(end($header)), ';')) {
                            array_unshift($own, array_pop($header));
                        }

                        $unit = ['file' => basename($path), 'header' => $header, 'lines' => [...$own, $line], 'open' => $headerDepth];
                        $depth += $opens - $closes;
                        if ($depth <= 0 && str_contains($line, ';')) {
                            $units[] = $unit;
                            $unit = null;
                            $depth = 0;
                        }

                        continue;
                    }

                    $header[] = $line;
                    // A braced `namespace Foo { .. }` leaves the header open; the snippet has to close
                    // it again or it will not parse on its own.
                    $headerDepth += $opens - $closes;

                    continue;
                }

                $unit['lines'][] = $line;
                $depth += $opens - $closes;
                if ($depth <= 0) {
                    $units[] = $unit;
                    $unit = null;
                    $depth = 0;
                }
            }
        }

        /**
         * @param array{file: string, header: list<string>, lines: list<string>, open: int} $unit
         */
        $render = static function (array $unit): string {
            $header = implode('', $unit['header']);
            // The corpus keeps its fixtures in one namespace; a snippet does not need it, but the
            // `use` statements are load-bearing since the rules resolve written names.
            $header = (string) preg_replace('/^namespace .*;\n\n?/m', '', $header);

            $closing = str_repeat("\n}", max(0, $unit['open']));

            return rtrim($header . implode('', $unit['lines']) . $closing) . "\n";
        };

        $bad = $blank;
        $good = $blank;
        foreach ($units as $example) {
            $body = implode('', $example['lines']);
            $fires = preg_match('/@fires[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            $silent = preg_match('/@silent[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            // A `@diverges` site fires in one tool and not the other, so it is neither a clean good
            // example nor a canonical bad one.
            $diverges = preg_match('/@diverges[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            $fires = $fires && ! $diverges;
            $silent = $silent && ! $diverges;
            if ($bad === $blank && $fires) {
                $bad = $render($example);
                // The rules that only look at test files need the snippet to be named like one, and
                // the harness lets an example say so.
                if ($ruleReadsTheFileName) {
                    $bad = (string) preg_replace('/^<\?php\n/', "<?php\n\n// file: " . $example['file'], $bad, 1);
                    $bad = (string) preg_replace('/^(<\?php\n\n\/\/ file: [^\n]*)/', '$1' . "\n", $bad, 1);
                }
            }

            if ($good === $blank && $silent && ! $fires) {
                $good = $render($example);
            }
        }

        return [$good, $bad];
    }
}
