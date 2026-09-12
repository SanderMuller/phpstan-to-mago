<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * All three `hasVariableType()` tails, asked both ways round.
 *
 * **This fixture is the only thing that exercises the definedness port end to end.** The three corpus rules
 * that want it -- `OverwriteVariablesWithForeachRule`, `DisallowedImplicitArrayCreationRule` and
 * `OverwriteVariablesWithForLoopInitRule` -- each stop at a further, unrelated obstacle, so lifting the
 * refusal made none of them emit and no corpus rule reaches this code. Without a fixture the whole path would
 * ship unexercised: the descriptor binding, the three-way tail table, and the name expression.
 *
 * Two receiver shapes, because they take different routes through the translator:
 *
 * - `$scope->hasVariableType($n)->yes()` is a chain, matched on the call.
 * - `$certainty = $scope->hasVariableType($n);` then `$certainty->no()` is a value bound to a local, which a
 *   chain-shaped match cannot see at all. `DisallowedImplicitArrayCreationRule` is written this way, and it
 *   is why the port carries a `definedness` descriptor rather than only a call pattern.
 *
 * The `no` and `maybe` arms report *different messages*, which is the reason all three tails exist as
 * separate helpers rather than two and a negation. Collapsing `maybe` into `! yes` would not widen this
 * rule's finding, it would give it the wrong text.
 *
 * `yes` is the guard rather than a fourth report, so the rule has a quiet case and can have a `Good` example
 * at all. A first draft reported on all three tails, which left every variable in every file reporting --
 * the three states are exhaustive -- and no file could be a `Good` one.
 *
 * **It hooks `Assign` and asks about the assigned *value*, and the fires gate is what forced that.**
 * Hooking every `Variable` asks the question at assignment *targets* too, and there the two engines
 * genuinely disagree: PHPStan answers `no` for `$x` in `$x = 'v'` and reports, where mago does not. The
 * right-hand side of an assignment is a read position, which is where the three corpus rules ask it and
 * where the engines agree. The divergence itself is real and is recorded rather than designed around in silence.
 *
 * @implements Rule<Assign>
 */
final class VariableDefinednessRule implements Rule
{
    public function getNodeType(): string
    {
        return Assign::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $returned = $node->expr;
        if (! $returned instanceof Variable || ! is_string($returned->name)) {
            return [];
        }

        if ($scope->hasVariableType($returned->name)->yes()) {
            return [];
        }

        $certainty = $scope->hasVariableType($returned->name);

        if ($certainty->no()) {
            return [
                RuleErrorBuilder::message('Variable does not exist.')
                    ->identifier('fixture.variableUndefined')
                    ->build(),
            ];
        }

        if ($certainty->maybe()) {
            return [
                RuleErrorBuilder::message('Variable might not exist.')
                    ->identifier('fixture.variableMaybe')
                    ->build(),
            ];
        }

        return [];
    }
}
