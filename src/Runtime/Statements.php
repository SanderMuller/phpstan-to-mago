<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;

/**
 * The two questions a rule asks of a statement it pulled out of a body.
 *
 * php-parser gives a body a flat `stmts` list whose members are the statements themselves, so a rule writes
 * `$stmt instanceof Stmt\Expression` and then `$stmt->expr`. Mago wraps each one: measured through
 * {@see Support::statementsOf()}, the items are `Statement` category nodes holding one concrete statement
 * each — `ExpressionStatement`, `If`, and so on. So both questions have to look one level down, and neither is
 * the identity php-parser makes it look like.
 *
 * `Statement` is deliberately *not* added to {@see Calls}' own wrapper list. That list is read by
 * `nthExpression()`, which sixteen navigations reach, and widening it would change what every one of them
 * unwraps for a runtime-only reason no emitted byte would record. Unwrapping here instead keeps the blast
 * radius at the two callers that need it.
 *
 * @internal to the runtime. An emitted plugin calls {@see Support}, never this.
 */
final class Statements
{
    /**
     * Whether this statement is an expression statement — `$stmt instanceof PhpParser\Node\Stmt\Expression`.
     *
     * The wrapper is why this is not a kind comparison on the item itself: every item a body yields is a
     * `Statement`, so comparing at that level answers no for an expression statement and yes for nothing.
     */
    public static function isExpressionStatement(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return self::concrete($context, $subject)?->kind->value === 'ExpressionStatement';
    }

    /**
     * The expression a statement holds — `$stmt->expr`.
     *
     * Reached through {@see Calls::nthExpression()} once the wrapper is off, so the same unwrapping every
     * other expression read uses applies here too: the `Expression` child is a wrapper of its own and the
     * rule wants what is inside it.
     *
     * Null for a statement that holds no expression, which is what a rule guarding with `instanceof
     * Stmt\Expression` first never sees and one without that guard has to handle.
     */
    public static function expressionOf(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $concrete = self::concrete($context, $subject);

        return $concrete instanceof Node ? Calls::nthExpression($context, $concrete, 0) : null;
    }

    /** The one concrete statement a `Statement` wrapper holds, or the node itself when it is not wrapped. */
    private static function concrete(NodeAnalysisContext $context, Part|Node|null $subject): ?Node
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        if ($node->kind->value !== 'Statement') {
            return $node;
        }

        return $context->source->getChildren($node)[0] ?? $node;
    }
}
