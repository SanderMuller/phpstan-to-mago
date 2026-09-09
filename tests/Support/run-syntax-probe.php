<?php

declare(strict_types=1);

/**
 * How mago represents a construct, answered by running mago rather than by reading its `NodeKind` list.
 *
 *   php tests/Support/run-syntax-probe.php '$r = &$a;' '(int) $s' 'function &f() {}'
 *
 * **This exists because "is there a node kind called X" is the wrong question, and it was asked three times
 * in one session with the wrong answer each time.** Grepping `NodeKind` for `Cast` finds nothing and a cast
 * is a `UnaryPrefix` whose operator carries the parentheses. Grepping for a reference finds nothing and `&`
 * is a `UnaryPrefixOperator` under an assignment's right-hand side -- but *not* on a parameter, where it is
 * in the source text only. In both cases the name was absent and the representation was not, and in both a
 * conclusion of "unportable" was written down before anything was run.
 *
 * The question this answers is **how is X represented**, which needs the tree rather than the enum:
 *
 * - Every node kind under the snippet, with the text it spans.
 * - `getDescendants()` rather than `getChildren()`, because the first reading of the reference case stopped
 *   at an `Expression` whose text was `&$a` and read as "no node for the ampersand". The node was one level
 *   further down.
 * - The inferred type of each expression, under `TargetExpressionTypes`, because a marker's absence from the
 *   tree does not mean the *answer* is unavailable -- a cast's own type is what `UselessCastRule` compares
 *   and it arrives through a requirement rather than through a child.
 *
 * Each snippet goes in its own function so one malformed one cannot take the others' output with it, and
 * every snippet is printed with its result so a reader can tell which rows came from which.
 */

use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<non-empty-string> $snippets */
$snippets = array_values(array_filter(
    array_slice((array) ($_SERVER['argv'] ?? []), 1),
    static fn (mixed $argument): bool => is_string($argument) && $argument !== '',
));

if ($snippets === []) {
    fwrite(STDERR, "usage: run-syntax-probe.php '<php snippet>' ['<php snippet>' ...]\n");

    exit(1);
}

$root = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-syntax-' . getmypid();
if (! is_dir($sandbox . '/src') && ! mkdir($sandbox . '/src', 0o777, true)) {
    fwrite(STDERR, "Could not create {$sandbox}\n");

    exit(1);
}

$bodies = '';
foreach ($snippets as $index => $snippet) {
    // A snippet may be a statement or an expression, and a bare expression is not a statement. Wrapped as an
    // assignment where it does not end in a semicolon, so both forms are accepted without the caller having
    // to know which the probe wants.
    $statement = str_ends_with(rtrim($snippet), ';') || str_ends_with(rtrim($snippet), '}')
        ? $snippet
        : '$probe' . $index . ' = ' . $snippet . ';';

    $bodies .= "    public function probe{$index}(string \$s, float \$f, int \$i, array \$a): void\n    {\n        {$statement}\n    }\n\n";
}

file_put_contents($sandbox . '/src/Probed.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace SyntaxProbe;\n\nfinal class Probed\n{\n" . $bodies . "}\n");

file_put_contents($sandbox . '/plugin.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace SyntaxProbe;

    use Mago\Sdk\Analyzer\FileAnalysisRequirement;
    use Mago\Sdk\Analyzer\NodeAnalysisContext;
    use Mago\Sdk\Analyzer\NodeAnalysisHook;
    use Mago\Sdk\Analyzer\Plugin;
    use Mago\Sdk\Analyzer\PluginDefinition;
    use Mago\Sdk\Analyzer\PluginRegistry;
    use Mago\Sdk\Syntax\NodeKind;

    final class SyntaxProbe implements NodeAnalysisHook, Plugin
    {
        public function getDefinition(): PluginDefinition
        {
            return new PluginDefinition(identifier: 'probe/syntax', name: 'SyntaxProbe', description: 'SyntaxProbe');
        }

        public function register(PluginRegistry $registry): void
        {
            $registry->registerNodeAnalysisHook($this);
        }

        /** @return non-empty-list<NodeKind> */
        public function getTargets(): array
        {
            return [NodeKind::Method];
        }

        /** @return non-empty-list<FileAnalysisRequirement> */
        public function getRequirements(): array
        {
            return [
                FileAnalysisRequirement::TargetSubtree,
                FileAnalysisRequirement::SourceText,
                FileAnalysisRequirement::ExpressionTypes,
            ];
        }

        public function analyze(NodeAnalysisContext $context): void
        {
            $file = $context->source;
            $name = '';
            foreach ($file->getChildren($context->node) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier) {
                    $name = trim($file->getText($child));

                    break;
                }
            }

            // From the body down, not from the method. The signature contributes two dozen rows about a
            // parameter list nobody probed for, and the construct under test is always inside the body -- so
            // the noise would be most of the output and the answer a line in it.
            $body = null;
            foreach ($file->getDescendants($context->node) as $candidate) {
                if ($candidate->kind === NodeKind::Block) {
                    $body = $candidate;

                    break;
                }
            }

            $out = "=== {$name}\n";
            foreach ($file->getDescendants($body ?? $context->node) as $node) {
                $text = str_replace("\n", ' ', trim($file->getText($node)));
                $type = $context->analysis->getExpressionType($node);
                $out .= sprintf(
                    "  %-26s %-30s %s\n",
                    $node->kind->value,
                    strlen($text) > 28 ? substr($text, 0, 27) . '…' : $text,
                    $type === null ? '' : (string) $type,
                );
            }

            file_put_contents((string) getenv('PROBE_OUT'), $out, FILE_APPEND);
        }
    }
    PHP);

file_put_contents($sandbox . '/worker.php', sprintf(
    <<<'PHP'
        <?php

        declare(strict_types=1);

        // A notice on stdout corrupts the extension frame — mago reads binary frames there.
        ini_set('display_errors', 'stderr');

        use Mago\Sdk\Extension;
        use Mago\Sdk\Worker;
        use SyntaxProbe\SyntaxProbe;

        require '%s/vendor/autoload.php';
        require __DIR__ . '/plugin.php';

        (new Worker(new Extension(
            identifier: 'probe/syntax',
            name: 'SyntaxProbe',
            version: '0.0.0',
            analyzerPlugins: [new SyntaxProbe()],
        )))->run();
        PHP,
    $root,
));

// No `includes`. The snippets name nothing outside themselves, and an include set would only add files to
// index.
file_put_contents($sandbox . '/mago.toml', <<<'TOML'
    [source]
    paths = ["src"]

    [extension-hosts.probe]
    command = ["php", "worker.php"]
    TOML);

$out = $sandbox . '/rows';
@unlink($out);

$process = proc_open(
    [$root . '/vendor/bin/mago', 'analyze', '--reporting-format', 'json'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $sandbox,
    [...Subprocess::environment(), 'PROBE_OUT' => $out],
);
if (! is_resource($process)) {
    fwrite(STDERR, "Could not start mago\n");

    exit(1);
}

$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[2]);
proc_close($process);

$rows = is_file($out) ? (string) file_get_contents($out) : '';
if (trim($rows) === '') {
    fwrite(STDERR, "The probe wrote nothing, so it never looked:\n" . substr($stderr, 0, 2000) . "\n");
    fwrite(STDERR, "The snippets are in {$sandbox}/src/Probed.php.\n");

    exit(1);
}

foreach ($snippets as $index => $snippet) {
    printf("probe%d: %s\n", $index, $snippet);
}

echo "\n", $rows;
