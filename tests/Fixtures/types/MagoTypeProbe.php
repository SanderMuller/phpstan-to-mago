<?php

declare(strict_types=1);

namespace TypeShapes;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Syntax\NodeKind;
use Sandermuller\PhpstanToMago\Runtime\Support;

/**
 * The same rows, from the only window a plugin has onto an inferred type: `Type::__toString()`.
 *
 * Writes two things per shape: what `Type::__toString()` renders, and what `Type::$atomicTypes` still holds of
 * whatever that rendering dropped. The second column is the point — the rendering being lossy says nothing
 * about the SDK withholding the information, and reading only the first column is how this repository came to
 * publish that it did.
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

    /**
     * What the atomics still carry of what the rendering dropped, as one `key=value` line.
     *
     * Read through the public API rather than by walking every property with reflection: an exploratory dump
     * crashed on an enum-typed field and would fail on any unrelated SDK addition, and what is being pinned is
     * four specific facts rather than the shape of the whole model.
     */
    private static function recoverable(Type $type): string
    {
        $facts = ['atomics=' . count($type->atomicTypes)];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && $atomic->intersections !== null) {
                foreach ($atomic->intersections as $member) {
                    $facts[] = 'intersection=' . ($member instanceof NamedObjectType ? $member->name : (string) $member);
                }
            }

            if ($atomic instanceof ScalarType && is_bool($atomic->refinement)) {
                $facts[] = 'refinement=' . ($atomic->refinement ? 'true' : 'false');
            }

            if ($atomic instanceof ListType) {
                $facts[] = 'element=' . $atomic->elementType;
            }
        }

        return implode(' ', $facts);
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
            $callee . "\t" . ($type === null ? '<no type>' : (string) $type)
                . "\t" . ($type === null ? '-' : self::recoverable($type)) . "\n",
            FILE_APPEND,
        );
    }
}
