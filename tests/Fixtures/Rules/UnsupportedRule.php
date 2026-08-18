<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Deliberately outside the vocabulary: the type of an arbitrary sub-expression is not a position the
 * Mago SDK hands to a node hook, so this must be refused rather than approximated.
 *
 * @implements Rule<MethodCall>
 */
final class UnsupportedRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $type = $scope->getType($node->var);
        if (! $type->isString()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message('String receiver')
                ->identifier('fixture.unsupported')
                ->build(),
        ];
    }
}
