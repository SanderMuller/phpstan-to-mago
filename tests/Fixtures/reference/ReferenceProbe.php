<?php

declare(strict_types=1);

namespace ReferenceShapes;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Reports, per `&` position, whether the ampersand is a node and what stands in front of the name.
 *
 * A rule reading `->byRef` needs one predicate per position, and which predicate depends on whether mago's
 * tree holds the ampersand. Both answers occur, which is the whole reason this probe exists: three positions
 * carry it as a `UnaryPrefixOperator` and two carry it in the text only, so a port written to either route
 * alone is silently wrong for the other.
 *
 * `getDescendants()` rather than `getChildren()`, and that is the load-bearing choice. On `$r = &$a` the
 * immediate children stop at `Expression` whose text is `&$a`, which reads as "no node for the ampersand"
 * and is how a first pass of this probe concluded that every position needed a text read. The node is one
 * level further down.
 */
final class ReferenceProbe implements NodeAnalysisHook, Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(identifier: 'probe/reference', name: 'ReferenceProbe', description: 'ReferenceProbe');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerNodeAnalysisHook($this);
    }

    /** @return non-empty-list<NodeKind> */
    public function getTargets(): array
    {
        return [
            NodeKind::Assignment,
            NodeKind::ArrayElement,
            NodeKind::ForeachValueTarget,
            NodeKind::FunctionLikeParameter,
            NodeKind::Method,
        ];
    }

    /** @return non-empty-list<FileAnalysisRequirement> */
    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $node = $context->node;

        $name = $this->nameNode($source, $node);
        if ($name === null) {
            return;
        }

        file_put_contents((string) getenv('PROBE_OUT'), sprintf(
            "%s:%s\tnode=%s\tbefore=%s\n",
            $node->kind->value,
            trim($source->getText($name)),
            $this->hasReferenceNode($source, $node) ? 'yes' : 'no',
            // What a span-gap predicate reads. `isVariadic()` in the runtime reads exactly this window for
            // `...`, so a `&` predicate for the two positions without a node is a sibling of it.
            trim($source->getText(new Span($node->span->start, $name->span->start))),
        ), FILE_APPEND);
    }

    /**
     * The variable or identifier this site is keyed on, so one row per site survives a re-run.
     *
     * Which kind names a site depends on the site, and getting that wrong hides rows rather than failing.
     */
    private function nameNode(SourceFile $source, Node $node): ?Node
    {
        // A method is keyed on its own name and the rest on their variable, chosen per kind rather than by
        // taking whichever comes first. Both orders were measured wrong: first-of-either keyed
        // `int &...$rest` on the hint's `int`, and variable-first keyed a method on the first variable in its
        // body, which is `$this`. Neither collapse is visible in a row -- the site simply never appears.
        $wanted = $node->kind === NodeKind::Method ? NodeKind::LocalIdentifier : NodeKind::DirectVariable;
        foreach ($source->getDescendants($node) as $descendant) {
            if ($descendant->kind === $wanted) {
                return $descendant;
            }
        }

        return null;
    }

    /** Whether the ampersand is in the tree, anywhere beneath this node. */
    private function hasReferenceNode(SourceFile $source, Node $node): bool
    {
        foreach ($source->getDescendants($node) as $descendant) {
            if ($descendant->kind === NodeKind::UnaryPrefixOperator && trim($source->getText($descendant)) === '&') {
                return true;
            }
        }

        return false;
    }
}
