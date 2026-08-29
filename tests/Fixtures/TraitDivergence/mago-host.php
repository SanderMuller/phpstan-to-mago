<?php

declare(strict_types=1);

/**
 * The mago half of the trait-divergence measurement: which class encloses every method the hook fires for.
 *
 * A bare `NodeKind::Method` hook, which is what every emitted plugin on this hook is, asked the same question
 * the PHPStan probe beside it asks. Reports nothing and writes to `TRAIT_PROBE_OUT`, so the two sides are
 * read the same way.
 */

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Worker;
use Sandermuller\PhpstanToMago\Runtime\Support;

require_once (string) getenv('TRAIT_PROBE_AUTOLOAD');

final class MagoProbe implements Plugin, NodeAnalysisHook
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('probe/trait-divergence', 'MagoProbe', 'Records the enclosing class of every method.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerNodeAnalysisHook($this);
    }

    public function getTargets(): array
    {
        return [NodeKind::Method];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        file_put_contents(
            (string) getenv('TRAIT_PROBE_OUT'),
            sprintf(
                "%s::%s\n",
                Support::enclosingClassName($context, $context->node) ?? '(none)',
                Support::declarationName($context, $context->node) ?? '?',
            ),
            FILE_APPEND,
        );
    }
}

(new Worker(new Extension('probe/trait-divergence', 'trait-divergence', '0.0.0', analyzerPlugins: [new MagoProbe()])))->run();
