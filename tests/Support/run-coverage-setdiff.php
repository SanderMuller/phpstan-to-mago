<?php

declare(strict_types=1);

/**
 * Which parameter declarations the two counters disagree about, in one file.
 *
 *   php tests/Support/run-coverage-setdiff.php <consumer-root> <file-relative-to-it>
 *
 * `run-coverage-corpus.php` gives a delta. A delta says how many and never which, and three plausible
 * explanations for one were refuted in a row by reasoning from totals. This names them.
 *
 * The trick is that `ParamTypeCoverageRule` reports one finding per *untyped* parameter, so a copy of the file
 * with every parameter type stripped makes the original enumerate exactly the set it counts. Stripping cannot
 * change the total: a declaration is counted whether or not its parameters carry types. The class is renamed
 * so the copy does not collide with the original, which stays in the resolution context of both tools.
 *
 * Writes nothing into the consumer.
 */
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageSetDiff;

require __DIR__ . '/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
if (count($arguments) < 2) {
    fwrite(STDERR, "usage: run-coverage-setdiff.php <consumer-root> <file-relative-to-it>\n");

    exit(1);
}

$consumer = (string) realpath(rtrim($arguments[0], '/'));
$subject = $consumer . '/' . ltrim($arguments[1], '/');
if (! is_file($subject)) {
    fwrite(STDERR, "No such file: {$subject}\n");

    exit(1);
}

$configurationFile = CorpusDifferential::configurationOf($consumer);
if (! is_file($configurationFile)) {
    fwrite(STDERR, "The consumer has neither phpstan.neon nor phpstan.neon.dist.\n");

    exit(1);
}

$workspace = sys_get_temp_dir() . '/phpstan-to-mago-setdiff';
$stripped = $workspace . '/stripped';
foreach ([$workspace, $stripped] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0o777, true)) {
        fwrite(STDERR, "Could not create {$directory}\n");

        exit(1);
    }
}

$existing = glob($stripped . '/*.php');
foreach ($existing === false ? [] : $existing as $stale) {
    unlink($stale);
}

$name = basename($subject, '.php');
$renamed = $name . 'SetDiffProbe';

$ast = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($subject));
if ($ast === null) {
    fwrite(STDERR, "Could not parse {$subject}\n");

    exit(1);
}

$traverser = new NodeTraverser();
$traverser->addVisitor(new class ($name, $renamed) extends NodeVisitorAbstract {
    public function __construct(private readonly string $from, private readonly string $to) {}

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Param) {
            $node->type = null;
        }

        if ($node instanceof ClassLike && (string) $node->name === $this->from) {
            $node->name = new Identifier($this->to);
        }

        return null;
    }
});

file_put_contents($stripped . '/' . $renamed . '.php', (new Standard())->prettyPrintFile($traverser->traverse($ast)));

$diff = new CoverageSetDiff(
    repositoryRoot: dirname(__DIR__, 2),
    consumerRoot: $consumer,
    configurationFile: $configurationFile,
    strippedDirectory: $stripped,
    strippedFile: $stripped . '/' . $renamed . '.php',
    sandbox: $workspace,
);

$sets = $diff->sets();

printf("%s\n  original counts %d, port counts %d\n\n", $subject, count($sets['original']), count($sets['port']));

foreach ($sets['onlyPort'] as $line => $declarations) {
    printf("  only the port, line %d: %s\n", $line, implode(' | ', $declarations));
}

foreach ($sets['onlyOriginal'] as $line) {
    printf("  only the original, line %d\n", $line);
}
