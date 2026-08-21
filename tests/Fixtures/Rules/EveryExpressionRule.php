<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * One rule over several node kinds, which is what naming the abstract `Expr` asks for.
 *
 * PHPStan fires this for every expression and the body branches on the concrete kind. The emitted plugin
 * registers one target per kind the branches name, and each branch becomes its own method — so a guard inside
 * one declines that branch rather than the whole rule, and `$node->name` is read by a helper that answers for
 * every kind registered.
 *
 * @implements Rule<Expr>
 */
final class EveryExpressionRule implements Rule
{
    public const string ERROR_MESSAGE = 'Name it out';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticPropertyFetch) {
            if (! $node->name instanceof Expr) {
                return [];
            }

            $ruleError = RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.everyExpression')
                ->build();

            return [$ruleError];
        }

        if ($node instanceof MethodCall || $node instanceof FuncCall) {
            if (! $node->name instanceof Expr) {
                return [];
            }

            $ruleError = RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.everyExpression')
                ->build();

            return [$ruleError];
        }

        return [];
    }
}
