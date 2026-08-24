<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use LogicException;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The navigation primitives every other runtime class reads the tree through.
 *
 * `node()` and `part()` convert between the two shapes the SDK hands over — a `Node` from the analysis and
 * a `Part` the emitted code passes around — and `locate()` walks up to an enclosing declaration. Thirty
 * methods call the first and sixteen the second, from every concern, which is why they sit apart rather
 * than with any one of them.
 *
 * @internal to the runtime. An emitted plugin calls {@see Support}, never this.
 */
final class Tree
{
    /**
     * The four kinds a class-like can be.
     *
     * Public because two concerns walk the tree looking for one — `Declares` for the enclosing declaration
     * and `Inheritance` for a parent — and duplicating the list is how the two drift apart.
     */
    public const array CLASS_LIKE_KINDS = ['Class', 'Interface', 'Trait', 'Enum'];

    /**
     * Every node of the given kinds anywhere below this one, which is php-parser's `NodeFinder::findInstanceOf()`.
     *
     * Recurses blindly, including into nested closures and functions, because php-parser does: a rule counting
     * nested `foreach` statements counts one written inside a closure too. Stopping at a function boundary would
     * be the port deciding something the rule does not.
     *
     * **The starting node counts.** php-parser's traverser visits the nodes it is given, so
     * `findInstanceOf($node, Foreach_::class)` inside a foreach finds that foreach. Which makes
     * `findInstanceOf($node->stmts, ..)` — what the rules actually write — the version that excludes it, and puts
     * the exclusion in the `->stmts` navigation where the rule put it. Skipping the root here instead would give
     * the same answer for every rule in the corpus and the wrong one for the first rule that passes a node.
     *
     * @param list<string> $kinds
     *
     * @return list<Part>
     */
    public static function findKind(NodeAnalysisContext $context, Part|Node|null $within, array $kinds): array
    {
        $node = Tree::node($within);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        $walk = function (Node $current) use (&$walk, $context, $kinds, &$out): void {
            if (in_array($current->kind->value, $kinds, true)) {
                $out[] = Tree::part($context, $current);
            }

            foreach ($context->source->getChildren($current) as $child) {
                $walk($child);
            }
        };
        $walk($node);

        return $out;
    }

    public static function part(NodeAnalysisContext $context, Node $node): Part
    {
        return new Part($node->kind, trim($context->source->getText($node)), $node, $context->source);
    }

    /**
     * Navigation takes either a raw node or an already-navigated part.
     *
     * A narrowing binding hands back a Part, and the rule then reads a field off it, so the same
     * helpers have to accept both without the generated code caring which it holds.
     */
    public static function node(Part|Node|null $subject): ?Node
    {
        return $subject instanceof Part ? $subject->node : $subject;
    }

    /**
     * The full tree of the file being analysed, and its nodes indexed by kind and span.
     *
     * One file, not a map of them: `getSourceFile()` is a host round-trip on first call and `getNodes()`
     * walks the whole tree, and a node hook asks per node, so calling them per question cost 6.4s wall and
     * 12.8s CPU on a 676-file corpus against 0.89s / 0.77s without. Memoising the current file brings that
     * back to 0.99s / 1.05s. A single slot keeps a long-lived worker bounded; hooks arrive grouped per
     * file, so a second file simply replaces the first.
     *
     * @var array{string, SourceFile, array<string, Node>}|null
     */
    private static ?array $tree = null;

    /**
     * The whole file, and this node's counterpart inside it.
     *
     * A node hook is handed `TargetSubtree`, which embeds "each targeted node's concrete-syntax subtree".
     * So the target's `parentId` is null and `$context->source->getAncestors()` is empty — every question
     * about an *enclosing* declaration silently answered "none", and five emitted rules reported nothing
     * for it while parsing, loading and running.
     *
     * `$context->analysis->getSourceFile()` returns the complete analysed syntax. The target cannot simply
     * be handed to it: the same node is a different object there, with a real parent chain, so it is
     * relocated by kind and span first.
     *
     * @return array{SourceFile, Node}
     */
    public static function locate(NodeAnalysisContext $context, Node $node): array
    {
        $path = $context->source->path;
        if (self::$tree === null || self::$tree[0] !== $path) {
            $file = $context->analysis->getSourceFile();
            $index = [];
            foreach ($file->getNodes() as $candidate) {
                $key = $candidate->kind->value . ':' . $candidate->span->start . ':' . $candidate->span->end;
                // Two nodes of one kind at one span would make the index lose an entry, and relocation
                // would then answer with the wrong node instead of failing. Detected here, where it costs
                // one lookup per node, rather than trusted: a span identifying a node is an assumption.
                if (isset($index[$key])) {
                    throw new LogicException(sprintf('Two %s nodes share offsets %d-%d in %s, so a span does not identify one.', $candidate->kind->value, $candidate->span->start, $candidate->span->end, $file->path));
                }

                $index[$key] = $candidate;
            }

            self::$tree = [$path, $file, $index];
        }

        [, $file, $index] = self::$tree;
        $key = $node->kind->value . ':' . $node->span->start . ':' . $node->span->end;
        $matches = isset($index[$key]) ? [$index[$key]] : [];

        // Neither branch below has been seen across the corpus. They throw rather than picking a candidate
        // because guessing here is how the original bug behaved: an unanswerable question that answers
        // anyway is invisible, and this method exists to stop exactly that.
        if ($matches === []) {
            throw new LogicException(sprintf(
                'No %s node at offsets %d-%d in the full tree of %s.',
                $node->kind->value,
                $node->span->start,
                $node->span->end,
                $file->path,
            ));
        }

        return [$file, $matches[0]];
    }
}
