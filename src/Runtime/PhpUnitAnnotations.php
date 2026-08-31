<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;

/**
 * A PHPUnit annotation written without the space that separates it from its value.
 *
 * `AnnotationHelper::processDocComment()` decides *and* builds the findings, so there is no message for the
 * transpiler to take and no question for it to turn into a guard. The helper is reproduced here instead, and
 * the rules around it — which class-likes count, and whose docblock is read — still come from their own
 * source.
 *
 * The reproduction is deliberately literal. The `preg_split` pattern, the named-group regex and the thirteen
 * names are copied rather than rewritten, for the reason `TypeCoverage::MAGIC_NAMES` is copied: an upstream
 * addition should arrive as a diff here rather than as a percentage that quietly stops matching. The
 * behaviour at the edges *is* the behaviour — `@covers` alone at the end of a line is an error because the
 * value group matches empty, and `@coversNothing` is not, because the greedy `[a-zA-Z]+` captures the whole
 * name and that name is not in the list.
 *
 * Two things about where a docblock comes from were measured rather than assumed, on a fixture run under
 * both engines:
 *
 * - **An ordinary `/* *\/` block comment is not a docblock.** PHPStan reads `getDocComment()`, which is a
 *   `Doc` node, and mago records the two as different trivia kinds — so a block comment holding `@covers` is
 *   silent on both sides. `Support::docblockText()` already answers this, and the good example pins it.
 * - **The finding lands on the declaration, not on the annotation.** `RuleErrorBuilder` with no `->line()`
 *   anchors on the node the rule fired for, so two bad annotations in one docblock are two findings on the
 *   same line with different messages. Measured at lines 11 and 17 of a fixture whose annotations sit on 8,
 *   9, 14 and 15.
 */
final class PhpUnitAnnotations
{
    /**
     * The annotations PHPUnit gives a value, copied from `AnnotationHelper::ANNOTATIONS_WITH_PARAMS`.
     *
     * @var list<string>
     */
    private const array WITH_PARAMS = [
        'backupGlobals',
        'backupStaticAttributes',
        'covers',
        'coversDefaultClass',
        'dataProvider',
        'depends',
        'group',
        'preserveGlobalState',
        'requires',
        'testDox',
        'testWith',
        'ticket',
        'uses',
    ];

    /** Reports every annotation in this declaration's docblock that is missing the space before its value. */
    public static function report(
        NodeAnalysisContext $context,
        Part|Node|null $declaration,
        string $identifier,
    ): void {
        $node = Tree::node($declaration);
        if (! $node instanceof Node) {
            return;
        }

        foreach (self::violations(Support::docblockText($context, $declaration)) as $message) {
            $context->report(
                Level::Error,
                $identifier,
                Issue::new(Support::viaTraitUsers($context, $node, $message), $node->span, 'here'),
            );
        }
    }

    /**
     * The messages `processDocComment()` would build for one docblock, in the order it builds them.
     *
     * Split out so the scan can be read and tested without a mago context around it.
     *
     * @return list<string>
     */
    public static function violations(?string $docblock): array
    {
        if ($docblock === null) {
            return [];
        }

        $lines = preg_split("/((\r?\n)|(\r\n?))/", $docblock);
        if ($lines === false) {
            return [];
        }

        $messages = [];
        foreach ($lines as $line) {
            // The original's own comment says why this is a regex rather than a parsed docblock: an invalid
            // annotation is not present in a resolved PHPDoc at all, so the text is the only place it exists.
            $matched = preg_match('/(?<annotation>@(?<property>[a-zA-Z]+)(?<whitespace>\s*)(?<value>.*))/', $line, $matches);
            if ($matched === false || $matches === []) {
                continue;
            }

            if (! in_array($matches['property'], self::WITH_PARAMS, true) || $matches['whitespace'] !== '') {
                continue;
            }

            $messages[] = 'Annotation "' . $matches['annotation'] . '" is invalid, "@' . $matches['property']
                . '" should be followed by a space and a value.';
        }

        return $messages;
    }
}
