<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks for every call and narrows to the two kinds it means, the way `phpstan-phpunit`'s assertion rules do.
 *
 * The narrowing reads like a body predicate and is one: the plugin registers every kind `CallLike` covers and
 * the guard declines the rest at analysis time, which is what the `Expr::class` rules already do. What makes
 * the *body* work across both kinds is structural rather than lucky — `MethodCall`, `StaticMethodCall` and
 * `NullSafeMethodCall` carry `Expression`, `ClassLikeMemberSelector` and `ArgumentList` in that order, probed
 * on all three — so reading the selector and the arguments needs no per-kind rebinding.
 *
 * @implements Rule<CallLike>
 */
final class EitherCallKindRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not call forbidden() this way';

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        if ($node->name->toString() !== 'forbidden') {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.eitherCallKind')
                ->build(),
        ];
    }
}
