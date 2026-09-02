<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Which operator a unary or binary expression was written with.
 *
 * Mago keeps `!$x`, `-$x`, `++$x` and `$x + 1` in three node kinds — `UnaryPrefix`, `UnaryPostfix` and
 * `Binary` — with the operator itself as a child. php-parser gives each operator its own class, so a hook
 * for one of those registers the kind and gates on the token: eight rules in `phpstan-strict-rules` are one
 * cell each of that grid.
 *
 * Three readers of one shape, split out of {@see Calls} when the third took it past the complexity limit.
 * They stay separate rather than becoming one public helper with a kind argument: a hook's gate names the
 * side it means, and a prefix `++` matching a postfix one would be a rule firing on a spelling it did not
 * register.
 */
final class Operators
{
    /**
     * Whether a unary prefix expression's operator is the one written.
     *
     * Probed: the operator is the first child and the operand the second.
     */
    public static function unaryOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::UnaryPrefixOperator, $operator);
    }

    /** Whether a postfix expression's operator is the one written — `$x++` rather than `++$x`. */
    public static function postfixOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::UnaryPostfixOperator, $operator);
    }

    /** Whether a binary expression's operator is the one written, which Mago keeps in a child node. */
    public static function binaryOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::BinaryOperator, $operator);
    }

    /** The first operator child of the given kind, compared as text. */
    private static function operatorIs(
        NodeAnalysisContext $context,
        Part|Node|null $subject,
        NodeKind $kind,
        string $operator,
    ): bool {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return false;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === $kind) {
                return trim($context->source->getText($child)) === $operator;
            }
        }

        return false;
    }
}
