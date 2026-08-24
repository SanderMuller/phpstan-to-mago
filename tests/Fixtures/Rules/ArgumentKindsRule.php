<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks the *kind* of an argument's value, which is what two `symplify` config-closure rules do.
 *
 * `instanceof FuncCall` and `instanceof ClassConstFetch` had no node predicate, and the refusal read as though
 * they existed for other positions — "no node predicate for instanceof FuncCall on a expr". They did not exist
 * at all. Both are here because both were added together, and a predicate nothing asks for is a table row
 * nobody checks.
 *
 * Mago wraps every call in a `Call` node whose first child carries the concrete kind, so the function-call
 * predicate unwraps that the way the method- and static-call ones do; asking the wrapper answers no for all
 * three. The good example holds a method call in the first position for exactly that reason — it is a call and
 * it is not a *function* call.
 *
 * @implements Rule<MethodCall>
 */
final class ArgumentKindsRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not configure with a call and a constant';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (count($node->getArgs()) !== 2) {
            return [];
        }

        $first = $node->getArgs()[0];
        if (! $first->value instanceof FuncCall) {
            return [];
        }

        $second = $node->getArgs()[1];
        if (! $second->value instanceof ClassConstFetch) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.argumentKinds')
                ->build(),
        ];
    }
}
