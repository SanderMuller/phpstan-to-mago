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
    /**
     * Whether this node's unary prefix operator is any of these, which is how a cast is recognised.
     *
     * **A cast is a `UnaryPrefix` in mago's tree**, with the operator carrying the written parentheses --
     * `(int)`, `(bool)`, `(object)`. Probed, with `-$f` beside them as the control that proves the operator
     * text discriminates: there is no `Cast` node kind, and concluding from that name's absence that a cast
     * is unrepresentable was wrong twice in this repository, once here and once for `&`.
     *
     * A set rather than one operator because php-parser's `Cast` is abstract: a rule declaring it fires on
     * every spelling, and PHP has two or three for most of them.
     *
     * @param list<string> $operators
     */
    public static function unaryOperatorIsOneOf(NodeAnalysisContext $context, Part|Node|null $subject, array $operators): bool
    {
        foreach ($operators as $operator) {
            if (self::unaryOperatorIs($context, $subject, $operator)) {
                return true;
            }
        }

        return false;
    }

    public static function postfixOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::UnaryPostfixOperator, $operator);
    }

    /** Whether a binary expression's operator is the one written, which Mago keeps in a child node. */
    public static function binaryOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::BinaryOperator, $operator);
    }

    /**
     * Whether a binary operator is one of several spellings, compared case-insensitively.
     *
     * PHPStan's `BooleanAndNode` is one virtual node over two php-parser classes -- `BooleanAnd` for `&&` and
     * `LogicalAnd` for `and` -- which is why the rule behind it reads `getOperatorSigil()` and picks its
     * identifier from which one it found. Mago has one `Binary` kind for every operator, so the hook gates on
     * the operator set rather than on a node class.
     *
     * Case-insensitively because `and` and `or` are keywords PHP accepts in any case, and the sigil a rule
     * prints is the source text.
     *
     * @param list<string> $operators
     */
    public static function binaryOperatorIsOneOf(
        NodeAnalysisContext $context,
        Part|Node|null $subject,
        array $operators,
    ): bool {
        $written = self::operatorText($context, $subject, NodeKind::BinaryOperator);
        if ($written === null) {
            return false;
        }

        foreach ($operators as $operator) {
            if (strcasecmp($written, $operator) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a compound assignment's operator is the one written — `/=` rather than `/`.
     *
     * The fourth sibling, and it earns its place the same way the other three do: an `Assignment` keeps its
     * operator in an `AssignmentOperator` child, so this answers false for a `Binary` and `binaryOperatorIs()`
     * answers false for an `Assignment`. That is what lets the six arithmetic rules gate on the operator alone
     * with no node-kind test beside it — php-parser splits `$a / $b` and `$a /= $b` into `BinaryOp\Div` and
     * `AssignOp\Div`, and here they are two kinds distinguished by which operator child they carry.
     */
    public static function assignmentOperatorIs(NodeAnalysisContext $context, Part|Node|null $subject, string $operator): bool
    {
        return self::operatorIs($context, $subject, NodeKind::AssignmentOperator, $operator);
    }

    /** The first operator child of the given kind, compared as text. */
    private static function operatorIs(
        NodeAnalysisContext $context,
        Part|Node|null $subject,
        NodeKind $kind,
        string $operator,
    ): bool {
        return self::operatorText($context, $subject, $kind) === $operator;
    }

    /**
     * The first operator child of the given kind, as written, or null when there is none.
     *
     * Read rather than compared, so a caller that needs the spelling — the sigil a `BooleanAndNode` rule
     * prints, `&&` or `and` — gets the same text the comparisons use.
     */
    public static function operatorText(
        NodeAnalysisContext $context,
        Part|Node|null $subject,
        NodeKind $kind = NodeKind::BinaryOperator,
    ): ?string {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === $kind) {
                return trim($context->source->getText($child));
            }
        }

        return null;
    }
}
