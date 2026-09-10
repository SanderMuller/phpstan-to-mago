<?php

declare(strict_types=1);

/**
 * What both engines answer about a *type* question, side by side.
 *
 *   php tests/Support/run-comparator-probe.php [<file-of-probe-calls>]
 *
 * **This exists because a type question cannot be settled by reading either engine.** `run-syntax-probe.php`
 * answers how mago represents a construct; this answers what mago *concludes* about a pair of types, against
 * what PHPStan concludes about the same pair. Mago's comparisons are RPCs to the host, so their semantics are
 * the host's; PHPStan's are trinary where mago's are boolean, so the mapping between them is a claim rather
 * than a definition.
 *
 * It was built to settle one: whether `! canBeIdentical($a, $b)` carries `isSuperTypeOf($b)->no()`, which is
 * the capability `MatchingTypeInSwitchCaseConditionRule` needs. The answer was five agreements and one
 * divergence, and the divergent row is the only one of the six that could have distinguished the two
 * candidate implementations -- mago's comparator does not model finality, so an interface against an
 * unrelated `final` class comes back "can be identical" where PHPStan says `no`.
 *
 * **The default pairs are a control set, not a sample.** Each varies one axis, and the finality row is the
 * discriminating one: the other five agree whether or not a comparator models finality, so a probe without
 * that row passes under an implementation that loses findings. A pair whose answer you already know is not a
 * control; a pair on which the two candidate answers differ is. {@see Types::typeIsSuperTypeOf()}
 * carries the measurement and what it licenses.
 *
 * Pass a file of your own to ask about other pairs. It needs a `probe($left, $right)` function and calls to
 * it; both engines read the inferred type of each argument, so a `@var` or a parameter type is how you say
 * what to compare.
 */

use Runtime\Types;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/phpstan-to-mago-comparator-' . getmypid();

foreach ([$sandbox . '/src', $sandbox . '/ps'] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }
}

$given = $argv[1] ?? null;
if ($given !== null && ! is_file($given)) {
    fwrite(STDERR, "no such file: {$given}\n");

    exit(1);
}

$default = <<<'FIXTURE'
    <?php

    declare(strict_types=1);

    namespace Probed;

    interface Alpha {}
    interface Beta {}
    final class Sealed {}
    class Open {}

    function probe(mixed $left, mixed $right): void {}

    function pairs(int $i, bool $b, string $s, Alpha $al, Beta $be, Sealed $se, Open $op): void
    {
        $true = true;

        probe($true, $i);
        probe($true, $b);
        probe($i, $s);
        probe($al, $be);
        probe($al, $se);
        probe($al, $op);
    }
    FIXTURE;

file_put_contents(
    $sandbox . '/src/Probed.php',
    $given === null ? $default . "\n" : (string) file_get_contents($given),
);

file_put_contents($sandbox . '/plugin.php', str_replace('{ROOT}', $root, <<<'PLUGIN'
    <?php

    declare(strict_types=1);

    namespace ComparatorProbe;

    use Mago\Sdk\Analyzer\FileAnalysisRequirement;
    use Mago\Sdk\Analyzer\NodeAnalysisContext;
    use Mago\Sdk\Analyzer\NodeAnalysisHook;
    use Mago\Sdk\Analyzer\Plugin;
    use Mago\Sdk\Analyzer\PluginDefinition;
    use Mago\Sdk\Analyzer\PluginRegistry;
    use Mago\Sdk\Syntax\NodeKind;
    use Sandermuller\PhpstanToMago\Runtime\Describe;
    use Sandermuller\PhpstanToMago\Runtime\Support;

    final class ComparatorProbe implements Plugin, NodeAnalysisHook
    {
        public function getDefinition(): PluginDefinition
        {
            return new PluginDefinition(
                identifier: 'probe/comparator',
                name: 'ComparatorProbe',
                description: 'ComparatorProbe',
            );
        }

        public function register(PluginRegistry $registry): void
        {
            $registry->registerNodeAnalysisHook($this);
        }

        /** @return non-empty-list<NodeKind> */
        public function getTargets(): array
        {
            return [NodeKind::FunctionCall];
        }

        /** @return non-empty-list<FileAnalysisRequirement> */
        public function getRequirements(): array
        {
            return [
                FileAnalysisRequirement::TargetSubtree,
                FileAnalysisRequirement::SourceText,
                FileAnalysisRequirement::ExpressionTypes,
                FileAnalysisRequirement::ArgumentTypes,
            ];
        }

        public function analyze(NodeAnalysisContext $context): void
        {
            $node = $context->node;
            if (Support::textOf(Support::nthExpression($context, $node, 0)) !== 'probe') {
                return;
            }

            $list = Support::argumentList($context, $node);
            $left = Support::expressionType($context, Support::argumentValue(Support::argumentAt($list, 0)));
            $right = Support::expressionType($context, Support::argumentValue(Support::argumentAt($list, 1)));

            // Written rather than reported, because a report is formatted by whichever reporter mago is
            // configured with and a row here has to be joinable with PHPStan's. The same reason
            // `run-syntax-probe.php` writes its rows to a file.
            $out = getenv('PROBE_OUT');
            if ($out === false || $left === null || $right === null) {
                return;
            }

            file_put_contents($out, sprintf(
                "%s\t%s\t%s\n",
                Describe::type($left) ?? '?',
                Describe::type($right) ?? '?',
                $context->types->canBeIdentical($left, $right) ? 'can-be' : 'disjoint',
            ), FILE_APPEND);
        }
    }
    PLUGIN));

file_put_contents($sandbox . '/worker.php', str_replace('{ROOT}', $root, <<<'WORKER'
    <?php

    declare(strict_types=1);

    ini_set('display_errors', 'stderr');

    use ComparatorProbe\ComparatorProbe;
    use Mago\Sdk\Extension;
    use Mago\Sdk\Worker;

    require '{ROOT}/vendor/autoload.php';
    require __DIR__ . '/plugin.php';

    (new Worker(new Extension(
        identifier: 'probe/comparator',
        name: 'ComparatorProbe',
        version: '0.0.0',
        analyzerPlugins: [new ComparatorProbe()],
    )))->run();
    WORKER));

file_put_contents($sandbox . '/mago.toml', <<<'TOML'
    [source]
    paths = ["src"]

    [extension-hosts.probe]
    command = ["php", "worker.php"]
    TOML);

file_put_contents($sandbox . '/ps/ProbeRule.php', <<<'RULE'
    <?php declare(strict_types=1);

    namespace ComparatorProbe;

    use PhpParser\Node;
    use PhpParser\Node\Expr\FuncCall;
    use PhpParser\Node\Name;
    use PHPStan\Analyser\Scope;
    use PHPStan\Rules\Rule;
    use PHPStan\Rules\RuleErrorBuilder;
    use PHPStan\Type\VerbosityLevel;

    /** @implements Rule<FuncCall> */
    class ProbeRule implements Rule
    {
        public function getNodeType(): string
        {
            return FuncCall::class;
        }

        public function processNode(Node $node, Scope $scope): array
        {
            if (! $node->name instanceof Name || $node->name->getLast() !== 'probe') {
                return [];
            }

            $args = $node->getArgs();
            if (count($args) !== 2) {
                return [];
            }

            $left = $scope->getType($args[0]->value);
            $right = $scope->getType($args[1]->value);
            $result = $left->isSuperTypeOf($right);

            return [RuleErrorBuilder::message(sprintf(
                "PAIR\t%s\t%s\t%s",
                $left->describe(VerbosityLevel::typeOnly()),
                $right->describe(VerbosityLevel::typeOnly()),
                $result->yes() ? 'yes' : ($result->no() ? 'no' : 'maybe'),
            ))->identifier('probe.pair')->build()];
        }
    }
    RULE);

file_put_contents($sandbox . '/ps/phpstan.neon', <<<NEON
    parameters:
        level: 0
        tmpDir: {$sandbox}/ps/tmp
    services:
        -
            class: ComparatorProbe\ProbeRule
            tags: [phpstan.rules.rule]
    NEON);

/**
 * Runs one engine and hands back what it wrote, because a row here has to be joinable with the other's.
 *
 * A named function rather than a closure: PHPStan does not apply a docblock to a closure assigned to a
 * variable, so the parameter shapes would be `array` and `proc_open()` would reject them.
 *
 * @param list<string>          $command
 * @param array<string, string> $environment
 *
 * @return array{string, string} stdout and stderr
 */
function probeRun(array $command, string $cwd, array $environment = []): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        [...Subprocess::environment(), ...$environment],
    );
    if (! is_resource($process)) {
        fwrite(STDERR, 'could not start ' . ($command[0] ?? '?') . "\n");

        exit(1);
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [$out, $err];
}

$rowFile = $sandbox . '/rows';
@unlink($rowFile);

[, $magoErr] = probeRun([$root . '/vendor/bin/mago', 'analyze'], $sandbox, ['PROBE_OUT' => $rowFile]);

$magoRows = [];
foreach (explode("\n", is_file($rowFile) ? (string) file_get_contents($rowFile) : '') as $line) {
    $parts = explode("\t", $line);
    if (count($parts) === 3) {
        $magoRows[$parts[0] . ' | ' . $parts[1]] = $parts[2];
    }
}

if ($magoRows === []) {
    fwrite(STDERR, "mago's probe wrote nothing, so it never looked:\n" . substr($magoErr, 0, 2000) . "\n");
    fwrite(STDERR, "The snippet is in {$sandbox}/src/Probed.php.\n");

    exit(1);
}

[$phpstanOut, $phpstanErr] = probeRun([
    'php',
    $root . '/vendor/bin/phpstan',
    'analyse',
    '--configuration=' . $sandbox . '/ps/phpstan.neon',
    '--autoload-file=' . $root . '/vendor/autoload.php',
    '-a',
    $sandbox . '/ps/ProbeRule.php',
    '--error-format=raw',
    '--no-progress',
    $sandbox . '/src',
], $sandbox . '/ps');

$phpstanRows = [];
foreach (explode("\n", $phpstanOut) as $line) {
    if (preg_match('/PAIR\t(.*)\t(.*)\t(yes|no|maybe)/', $line, $match) === 1) {
        $phpstanRows[$match[1] . ' | ' . $match[2]] = $match[3];
    }
}

if ($phpstanRows === []) {
    fwrite(STDERR, "PHPStan's probe wrote nothing, so it never looked:\n" . substr($phpstanErr . $phpstanOut, 0, 2000) . "\n");

    exit(1);
}

echo "\n  canBeIdentical against isSuperTypeOf, both engines run over one file.\n";
echo "  `disjoint` should carry `no`; every other combination is a divergence to read.\n\n";

$width = 0;
foreach (array_keys($magoRows + $phpstanRows) as $pair) {
    $width = max($width, strlen($pair));
}

$diverged = 0;
foreach ($magoRows as $pair => $mago) {
    $phpstan = $phpstanRows[$pair] ?? '(not reached)';
    $agrees = ($mago === 'disjoint') === ($phpstan === 'no');
    $diverged += $agrees ? 0 : 1;
    printf("  %-{$width}s  mago %-8s  phpstan %-6s %s\n", $pair, $mago, $phpstan, $agrees ? 'agree' : 'DIVERGES');
}

echo "\n  " . count($magoRows) . ' pair(s), ' . $diverged . " divergence(s).\n";
echo "  A divergence where mago says `can-be` and PHPStan says `no` loses a finding rather than\n"
    . "  inventing one, so no differential over real code can see it.\n";
