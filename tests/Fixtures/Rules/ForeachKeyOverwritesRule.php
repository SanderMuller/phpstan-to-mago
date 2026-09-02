<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `$node->keyVar`, which is null on a foreach written without a key.
 *
 * The navigation half of `phpstan-strict-rules`' `OverwriteVariablesWithForeachRule`, on its own. That rule
 * cannot be emitted whole for two reasons this fixture deliberately leaves out: it asks
 * `$scope->hasVariableType()`, which renders for the Rust targets and not for this one, and its second half
 * inlines a helper that calls itself for each item of a `list()` target, which this transpiler refuses. So
 * the fixture reports every keyed loop instead of every overwriting one, and the presence of the key is then
 * the only thing the two engines can disagree on.
 *
 * php-parser spells the absent key as `keyVar === null`. Mago hangs a `ForeachTarget` off the loop holding
 * one of two kinds — `ForeachValueTarget` with a single expression, or `ForeachKeyValueTarget` with the key
 * first and the value second — so the null is a kind rather than a missing child. The pair under
 * `examples/` is where that equivalence is measured rather than argued.
 *
 * @implements Rule<Foreach_>
 */
final class ForeachKeyOverwritesRule implements Rule
{
    public const string ERROR_MESSAGE = 'This foreach binds a key variable';

    public function getNodeType(): string
    {
        return Foreach_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->keyVar instanceof Variable) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.foreachKeyOverwrite')
                ->build(),
        ];
    }
}
