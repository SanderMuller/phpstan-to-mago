<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;
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
 * It targeted `Expr\ConstFetch` until that gained a hook, and `Stmt\Expression` is the replacement because
 * three corpus rules still refuse on it. The node type here has to be one the vocabulary does not map, so
 * this fixture moves whenever the vocabulary catches up with it — which is the fixture working.
 *
 * @implements Rule<Expression>
 */
final class UnmappedNodeTypeRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not fetch that constant';


    public function getNodeType(): string
    {
        return Expression::class;
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
