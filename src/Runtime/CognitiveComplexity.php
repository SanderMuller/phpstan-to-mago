<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The Sonar cognitive-complexity score of a function-like, as `tomasvotruba/cognitive-complexity` computes it.
 *
 * Pure syntax: no types, no inference, so none of the "the two engines answer differently" divergences that
 * every other ported rule has to manage can arise here. What can go wrong instead is the *table*, and these
 * are threshold rules — a score off by one changes a finding at the boundary and nowhere else, which a
 * fixture pair hides and a corpus run does not.
 *
 * So the kind mapping was measured rather than assumed, by
 * `internal/probe-cognitive-complexity-kinds.php`. Three rows are not one-to-one:
 *
 * - **`else` and `elseif` are clauses, not statements.** Mago nests them under the `If`'s body as
 *   `IfStatementBodyElseClause` / `…ElseIfClause`. Deeper than php-parser puts them, but neither wrapper is a
 *   nesting kind, so the level at the clause equals the level inside the `If` — which is what php-parser
 *   gives, and in the same visit order.
 * - **`&&` has no kind of its own.** It is a `Binary` whose `BinaryOperator` child reads `&&`, so the table
 *   cannot be a kind set alone.
 * - **A levelled `break` is the only one that counts.** The original skips a bare `break;` and counts
 *   `break 2;`, testing `$node->num instanceof Expr`; here that is whether the `Break` node has an
 *   `Expression` child.
 *
 * Two quirks of the original are reproduced deliberately, because agreeing with it is the contract:
 *
 * - **A nesting node raises the level before its own increment is weighted.** `enterNode` increments the
 *   level first and only then asks whether the node increments, so an `if` is weighted at the level it
 *   itself created. That is what makes `foreach { if { while } }` score 6 rather than 3.
 * - **The weight is `level - 2`, and only when the level is deeper than the last one weighted.** Two
 *   siblings at the same depth are one increment each with no nesting bonus for the second; only going
 *   deeper pays.
 *
 * One divergence, stated because it cannot be reproduced rather than because it is acceptable: the original's
 * `NestingNodeVisitor::reset()` clears the measured level but *not* `previousNestingLevel`, and the visitor is
 * a shared service, so that value leaks from one analysed function into the next in whatever order PHPStan
 * visited them. A leak across a run's traversal order is not portable; this resets both per call, which is the
 * evidently intended behaviour. Where the leak would suppress a nesting increment, the two disagree — and the
 * corpus differential is what says whether that ever happens on real code.
 */
final class CognitiveComplexity
{
    /**
     * Kinds that raise the nesting level, mirroring `NestingNodeVisitor::NESTING_NODE_TYPES`.
     *
     * `Closure` is here and `ArrowFunction` is not, matching the original: a closure's body contributes to the
     * enclosing function's score at a raised level, and an arrow function neither nests nor increments.
     *
     * @var list<string>
     */
    private const array NESTING = ['If', 'For', 'While', 'TryCatchClause', 'Closure', 'Foreach', 'DoWhile', 'Conditional'];

    /**
     * Kinds that add one, mirroring `ComplexityAffectingNodeFinder::INCREASING_NODE_TYPES`.
     *
     * `Binary` is absent: `&&` is a `Binary` and so is every other operator, so it is decided on the operator
     * text instead. See {@see increments()}.
     *
     * @var list<string>
     */
    private const array INCREMENTING = [
        'If',
        'IfStatementBodyElseClause',
        'IfStatementBodyElseIfClause',
        'Switch',
        'For',
        'Foreach',
        'While',
        'DoWhile',
        'TryCatchClause',
        'Conditional',
    ];

    /** The score of one function, method or closure declaration. */
    public static function forFunctionLike(SourceFile $source, Node $functionLike): int
    {
        $state = ['operations' => 0, 'nesting' => 0, 'level' => 1, 'previous' => 0];
        self::walk($source, $functionLike, $state);

        return $state['operations'] + $state['nesting'];
    }

    /** The sum over a class-like's own methods, as `analyzeClassLike()` is. */
    public static function forClassLike(SourceFile $source, Node $classLike): int
    {
        $total = 0;
        foreach ($source->getChildren($classLike) as $member) {
            foreach ($source->getDescendants($member, NodeKind::Method) as $method) {
                $total += self::forFunctionLike($source, $method);
            }
        }

        return $total;
    }

    /**
     * One depth-first pass in document order, with the enter/leave semantics the original's traverser has.
     *
     * @param array{operations: int, nesting: int, level: int, previous: int} $state
     */
    private static function walk(SourceFile $source, Node $node, array &$state): void
    {
        $nests = in_array($node->kind->value, self::NESTING, true);
        if ($nests) {
            ++$state['level'];
        }

        if (self::increments($source, $node)) {
            ++$state['operations'];

            if (self::isBreaking($source, $node)) {
                // A breaking node adds one and no nesting, and still moves the mark — the original returns
                // early after setting it.
                $state['previous'] = $state['level'];
            } else {
                if ($state['level'] > 1 && $state['previous'] < $state['level']) {
                    $state['nesting'] += $state['level'] - 2;
                }

                $state['previous'] = $state['level'];
            }
        }

        foreach ($source->getChildren($node) as $child) {
            self::walk($source, $child, $state);
        }

        if ($nests) {
            --$state['level'];
        }
    }

    /** Whether a node adds one to the operation count. */
    private static function increments(SourceFile $source, Node $node): bool
    {
        if (in_array($node->kind->value, self::INCREMENTING, true)) {
            return true;
        }

        if ($node->kind === NodeKind::Binary) {
            return self::operatorText($source, $node) === '&&';
        }

        return self::isBreaking($source, $node);
    }

    /**
     * Whether a node is a break the original counts: any `goto`, and a `break`/`continue` carrying a level.
     *
     * A bare `break;` is skipped — the original says so in a comment and tests `$node->num instanceof Expr`,
     * which here is whether the node holds an `Expression` child.
     */
    private static function isBreaking(SourceFile $source, Node $node): bool
    {
        if ($node->kind === NodeKind::Goto) {
            return true;
        }

        if ($node->kind !== NodeKind::Break && $node->kind !== NodeKind::Continue) {
            return false;
        }

        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::Expression) {
                return true;
            }
        }

        return false;
    }

    /** The operator of a `Binary`, read from its `BinaryOperator` child. */
    private static function operatorText(SourceFile $source, Node $node): ?string
    {
        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::BinaryOperator) {
                return trim($source->getText($child));
            }
        }

        return null;
    }
}
