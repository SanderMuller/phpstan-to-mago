<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * One name bound two ways by a branch, which is a ternary written long.
 *
 * Three corpus rules write this — a static call's receiver is a resolved name when the class is written and a
 * rendered type when it is not, and the message quotes whichever it was. None of them emits: each ends by
 * asking `getType()` of a position the translation models as a written name, whatever the branch it is in.
 * So this fixture is what gates the binding itself.
 *
 * @implements Rule<MethodCall>
 */
final class BranchBoundLabelRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name instanceof Identifier) {
            $label = $node->name->toString();
        } else {
            $label = $scope->getType($node->var)->describe(VerbosityLevel::typeOnly());
        }

        return [
            RuleErrorBuilder::message(sprintf('Method call labelled %s.', $label))
                ->identifier('fixture.branchBoundLabel')
                ->build(),
        ];
    }
}
