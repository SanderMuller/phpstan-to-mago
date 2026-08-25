<?php
declare(strict_types=1);

namespace RenderCensus;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Syntax\NodeKind;
use ReflectionClass;

/**
 * Every type a renderer would meet, and which of them `Type::__toString()` gets wrong.
 *
 * The positions are the ones the 27 customers read from: conditions, arithmetic operands and receivers.
 * Nothing is assumed about an atomic's shape — a probe that reached for a property some atomic class does
 * not have killed the worker after 23 calls and looked like "the hook barely fires".
 */
final class Probe implements NodeAnalysisHook, Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(identifier:'probe/render', name:'R', description:'R');
    }

    public function register(PluginRegistry $r): void
    {
        $r->registerNodeAnalysisHook($this);
    }

    /**
     * @return NodeKind[]
     */
    public function getTargets(): array
    {
        return [NodeKind::If, NodeKind::While, NodeKind::DoWhile, NodeKind::Switch, NodeKind::Conditional,
            NodeKind::UnaryPrefix, NodeKind::Binary, NodeKind::MethodCall, NodeKind::StaticMethodCall];
    }

    /**
     * @return FileAnalysisRequirement[]
     */
    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $c): void
    {
        $out = '';
        foreach ($c->source->getChildren($c->node) as $child) {
            $type = $c->analysis->getExpressionType($child);
            if (! $type instanceof Type) {
                continue;
            }

            $out .= $this->row($type);
        }

        if ($out !== '') {
            file_put_contents('rows.jsonl', $out, FILE_APPEND);
        }
    }

    private function row(Type $type): string
    {
        $kinds = [];
        $flags = [];
        $atomics = $type->atomicTypes;
        foreach ($atomics as $a) {
            $vars = get_object_vars($a);
            $kind = (new ReflectionClass($a))->getShortName();
            if (isset($vars['kind']) && is_object($vars['kind']) && property_exists($vars['kind'], 'name')) {
                $kind .= ':' . $vars['kind']->name;
            }

            $kinds[] = $kind;
            if (! empty($vars['intersections'])) {
                $flags[] = 'intersection';
            }

            if (($vars['parameters'] ?? null) !== null) {
                $flags[] = 'generic';
            }

            if (($vars['elementType'] ?? null) !== null) {
                $flags[] = 'generic';
            }

            if (($vars['refinement'] ?? null) !== null && ($vars['kind']->name ?? '') === 'Bool') {
                $flags[] = 'literal-bool';
            }
        }

        if (count($atomics) === 2) {
            $names = array_map(fn (AtomicType $a) => (new ReflectionClass($a))->getShortName() . ':' . (get_object_vars($a)['kind']->name ?? ''), $atomics);
            $hasNull = in_array('SimpleAtomicType:Null', $names, true);
            $hasScalar = (bool) array_filter($names, fn (string $n) => str_starts_with($n, 'ScalarType:'));
            if ($hasNull && $hasScalar) {
                $flags[] = 'nullable-scalar';
            }
        }

        return json_encode(['k' => $kinds, 'f' => array_values(array_unique($flags))]) . "\n";
    }
}
