<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks `$scope->isInClass()` from a hook that fires outside a class as well as inside one.
 *
 * `FunctionLike` is the shape that makes the question real: the plugin registers `Method`, `Function`,
 * `Closure` and `ArrowFunction`, so the answer varies at runtime. A rule whose hook is a class declaration or a
 * method can have the guard folded away — `isInClass()` is true there by construction — and this one cannot.
 *
 * Folding it anyway would report every plain function in the project, which is the widening this fixture
 * exists to catch. The corpus cannot: no rule in it declares `FunctionLike` and asks the question, so the
 * narrowing had nothing to prove it until this.
 *
 * @implements Rule<FunctionLike>
 */
final class FunctionOrMethodScopeRule implements Rule
{
    public const string ERROR_MESSAGE = 'Declare this outside a class';

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $scope->isInClass()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.functionOrMethodScope')
                ->build(),
        ];
    }
}
