<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * How a call's arguments were *written*, as opposed to what they hold.
 *
 * Split from {@see Calls} rather than added to it, because adding it took that class to 85 against a limit of
 * 80 and the runtime is out of the baseline -- which is the property this repository keeps rather than a
 * number it tracks. {@see Support} still delegates, so no emitted byte moved: a plugin calls `Support::x()`
 * and the facade decides where that lives.
 *
 * One question so far, and it is about the writing rather than the value, which is why it does not belong
 * beside the readers in `Calls`.
 */
final class Arguments
{
    /**
     * Whether any argument of this call is written with a parameter name.
     *
     * The guard that stands in for PHPStan's argument normalisation. `ArgumentsNormalizer::reorderArgs()`
     * returns `array_values($callArgs)` before it ever reads the parameter list -- phar `:214` against
     * `getParameters()` first touched at `:218` -- so on a positional call the acceptor it is handed is dead
     * ceremony, and reading the Nth argument needs no signature at all. A *named* call is the only shape
     * where the normaliser does work, and this refuses there rather than reordering.
     *
     * **The bound is measured, not assumed.** Across the five vendored trees the yield instrument reads,
     * 3630 files: 2 of 868 calls to the four functions `StrictFunctionCallsRule` names carry a named
     * argument, both `in_array(needle: .., haystack: .., strict: true)` in Laravel's `Rules\Enum`. Both
     * supply the strictness argument as `true`, so the original is silent on them and so is a port that skips
     * them -- the divergence is zero on the corpus rather than small. It is not zero in principle: a named
     * call whose strictness argument is missing or false is reported by PHPStan and skipped here.
     */
    public static function hasNamedArgument(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $list = Calls::argumentList($context, $subject);
        $node = Tree::node($list);
        if (! $node instanceof Node) {
            return false;
        }

        foreach ($context->source->getChildren($node) as $argument) {
            if ($argument->kind === NodeKind::NamedArgument) {
                return true;
            }

            foreach ($context->source->getChildren($argument) as $inner) {
                if ($inner->kind === NodeKind::NamedArgument) {
                    return true;
                }
            }
        }

        return false;
    }
}
