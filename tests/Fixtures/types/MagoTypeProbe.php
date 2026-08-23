<?php

declare(strict_types=1);

namespace TypeShapes;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Syntax\NodeKind;
use Sandermuller\PhpstanToMago\Runtime\Support;

/**
 * The same rows, from the only window a plugin has onto an inferred type: `Type::__toString()`.
 *
 * `ExpressionTypes` is declared because every emitted plugin that asks a sub-expression's type declares it. It
 * is not what makes the types arrive here, though: removing it leaves every row unchanged. That is a narrower
 * result than "it is never needed" — this probe reads an argument of its own target node, so nothing here says
 * anything about a position outside the target subtree.
 */
final class MagoTypeProbe implements NodeAnalysisHook, Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(identifier: 'probe/type', name: 'MagoTypeProbe', description: 'Renders inferred types');
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

    /** @return list<FileAnalysisRequirement> */
    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $node = $context->node;
        $name = Support::nthExpression($context, $node, 0);
        if (! Support::isName($name)) {
            return;
        }

        $callee = Support::textOf($name);
        if ($callee === null || ! str_starts_with($callee, 'probe_')) {
            return;
        }

        $type = Support::expressionType($context, Support::positionalArgAt(Support::argumentList($context, $node), 0));

        file_put_contents(
            (string) getenv('PROBE_OUT'),
            $callee . "\t" . ($type === null ? '<no type>' : (string) $type) . "\n",
            FILE_APPEND,
        );
    }
}
