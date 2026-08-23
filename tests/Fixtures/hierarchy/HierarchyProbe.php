<?php

declare(strict_types=1);

namespace HierarchyShapes;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Syntax\NodeKind;
use Sandermuller\PhpstanToMago\Runtime\Support;

/**
 * Reports, per probed call, what the codebase can and cannot say about the enclosing class's ancestry.
 *
 * The question behind it: PHPStan's `TrinaryLogic` has no Mago equivalent, so every rule reading
 * `->isSuperTypeOf(..)->yes()` or `->no()` needs an answer to "what does the port say when Mago cannot tell".
 * Before deciding that, it has to be established that *cannot tell* is a state a plugin can observe at all —
 * Mago skips the body of a class whose parent it cannot resolve, so an unresolvable ancestor might never reach
 * a hook.
 *
 * `ClassLikeMetadata::hasIncompleteHierarchy()` is what makes it observable, and
 * `$unresolvedHierarchyDependencies` names which dependency is missing.
 */
final class HierarchyProbe implements NodeAnalysisHook, Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(identifier: 'probe/hierarchy', name: 'HierarchyProbe', description: 'HierarchyProbe');
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
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $name = Support::nthExpression($context, $node = $context->node, 0);
        if (! Support::isName($name)) {
            return;
        }

        $callee = Support::textOf($name);
        if ($callee === null || ! str_starts_with($callee, 'probe_')) {
            return;
        }

        $class = Support::enclosingClassName($context, $node) ?? '<none>';
        $enclosing = $class === '<none>' ? null : $context->codebase->getClass($class);
        $ancestors = $class === '<none>' ? [] : $context->codebase->getClassAncestors($class);

        file_put_contents((string) getenv('PROBE_OUT'), sprintf(
            "%s\tclass=%s\tincomplete=%s\tunresolved=[%s]\tancestors=[%s]\thasTarget=%s\tmissingExists=%s\n",
            $callee,
            $class,
            $enclosing === null ? '-' : ($enclosing->hasIncompleteHierarchy() ? 'yes' : 'no'),
            $enclosing === null ? '' : implode(',', $enclosing->unresolvedHierarchyDependencies),
            implode(',', $ancestors),
            in_array('maybe\target', array_map('strtolower', $ancestors), true) ? 'yes' : 'no',
            $context->codebase->classExists('Nowhere\Missing') ? 'yes' : 'no',
        ), FILE_APPEND);
    }
}
