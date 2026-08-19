<?php

declare(strict_types=1);

namespace Fixture\Collectors;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * A collector whose only consumer writes a file.
 *
 * @implements Collector<MethodCall, array{line: int}>
 */
final class ManifestCollector implements Collector
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        return ['line' => $node->getStartLine()];
    }
}
