<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Label;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks PHPStan for a node type this vocabulary maps to no hook, and then needs something of the body too.
 *
 * Both halves matter. A rule with no hook cannot become a plugin whatever its body says, so an emit run
 * refuses on the hook; a survey run assumes the hook on purpose, to report what the body would need as well.
 * The fixture exists to pin that the survey says which of those it did — a body-level gap reported without
 * the assumption behind it reads as the only thing in the way, and closing it would move nothing.
 *
 * It targeted `Expr\ConstFetch`, then `Stmt\Expression`, and each moved when the vocabulary caught up —
 * which is the fixture working. `Stmt\Label` is the replacement, and it is chosen the other way round from
 * its predecessors: they were node types corpus rules wanted, so mapping them was a matter of time. A `goto`
 * label is one no rule in any installed package hooks, so this fixture should sit still.
 *
 * The node type here has only to be one the vocabulary does not map. If that ever stops being true of `Label`,
 * move it again rather than mapping around it.
 *
 * @implements Rule<Label>
 */
final class UnmappedNodeTypeRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not fetch that constant';

    public function getNodeType(): string
    {
        return Label::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // A scope query the vocabulary maps nowhere, so the survey has a body gap to report once it has
        // assumed the hook. What it is does not matter; that there is one does.
        if ($scope->getAnonymousFunctionReflection() !== null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.unmappedNodeType')
                ->build(),
        ];
    }
}
