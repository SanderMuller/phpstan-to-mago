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

        $units = [];
        $paths = glob($this->directory . '/*.php');
        foreach ($paths === false ? [] : $paths as $path) {
            $units = [...$units, ...$this->unitsIn($path)];
        }

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
                $bad = $this->render($example);
                // The rules that only look at test files need the snippet to be named like one, and
                // the harness lets an example say so.
                if ($ruleReadsTheFileName) {
                    $bad = (string) preg_replace('/^<\?php\n/', "<?php\n\n// file: " . $example['file'], $bad, 1);
                    $bad = (string) preg_replace('/^(<\?php\n\n\/\/ file: [^\n]*)/', '$1' . "\n", $bad, 1);
                }
            }

            if ($good === $blank && $silent && ! $fires) {
                $good = $this->render($example);
            }
        }

        return [$good, $bad];
    }

    /**
     * The top-level declarations one file holds, each with the header it needs to parse on its own.
     *
     * Extracted so the shape is declared at a boundary rather than asserted inside one method. Inline
     * `@var` on `$units` and `$unit` used to stand in for that, and it did not reach the `$render` closure
     * below: `$unit['lines'][] = $line` on a variable that is also assigned `null` left PHPStan with
     * `array{file: .., header: .., lines: .., open: int}|array{lines: non-empty-list<string>}`, which is
     * four baseline entries and no fault in the code. A declared return type answers all four.
     *
     * @return list<array{file: string, header: list<string>, lines: list<string>, open: int}>
     */
    private function unitsIn(string $path): array
    {
        $units = [];
        $read = file($path);
        $lines = $read === false ? [] : $read;
        $header = [];
        $headerDepth = 0;
        $depth = 0;
        // The open unit as three separate values rather than one array being mutated. `$unit['lines'][] =`
        // on a shaped array is what PHPStan cannot follow — it widens the whole shape to
        // `non-empty-array<'file'|'header'|'lines'|'open', int|list<string>|string>` and the shape is gone.
        // Building the array once, where the unit closes, keeps it.
        $openLines = null;
        $openHeader = [];
        $openDepth = 0;
        foreach ($lines as $line) {
            $opens = substr_count($line, '{');
            $closes = substr_count($line, '}');
            if ($depth === 0 && $openLines === null) {
                // A top-level declaration starts here, or this is still the file header.
                if (preg_match('/^\s*(final |abstract |readonly )*(class|interface|trait|enum|function|const) /', $line) === 1) {
                    // A docblock or comment run immediately above belongs to this declaration, not
                    // to the file, or it would be repeated on top of every later unit.
                    $own = [];
                    while ($header !== [] && trim(end($header)) !== '' && ! str_ends_with(rtrim(end($header)), ';')) {
                        array_unshift($own, array_pop($header));
                    }

                    $openHeader = $header;
                    $openDepth = $headerDepth;
                    $openLines = [...$own, $line];
                    $depth += $opens - $closes;
                    if ($depth <= 0 && str_contains($line, ';')) {
                        $units[] = ['file' => basename($path), 'header' => $openHeader, 'lines' => $openLines, 'open' => $openDepth];
                        $openLines = null;
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

            $openLines[] = $line;
            $depth += $opens - $closes;
            if ($depth <= 0) {
                $units[] = ['file' => basename($path), 'header' => $openHeader, 'lines' => $openLines, 'open' => $openDepth];
                $openLines = null;
                $depth = 0;
            }
        }

        return $units;
    }

    /**
     * One unit as a standalone snippet: its header, its own lines, and whatever braces the header left open.
     *
     * @param array{file: string, header: list<string>, lines: list<string>, open: int} $unit
     */
    private function render(array $unit): string
    {
        $header = implode('', $unit['header']);
        // The corpus keeps its fixtures in one namespace; a snippet does not need it, but the
        // `use` statements are load-bearing since the rules resolve written names.
        $header = (string) preg_replace('/^namespace .*;\n\n?/m', '', $header);

        $closing = str_repeat("\n}", max(0, $unit['open']));

        return rtrim($header . implode('', $unit['lines']) . $closing) . "\n";
    }
}
