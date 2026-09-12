<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\VariableDefinedness;

/**
 * Whether a local variable exists before the hooked node runs, which is PHPStan's `hasVariableType()`.
 *
 * **This is the capability the census recorded as absent for three releases, and it is worth saying why the
 * record stood rather than being probed away.** `Translator::definednessTest()` refused the PHP target on
 * `carthage-software/mago#2334`, and the refusal was true when written: a plugin received span-keyed types
 * and no definedness. A claim that something is *blocked* suppresses the work that would falsify it -- nobody
 * probes a capability their own docblock calls absent -- so it cannot be caught the way an optimistic claim
 * is, by a gate failing. What caught it is `RecheckesAnUpstreamBlockWhenMagoMovesTest`, which asserts the
 * version the claim was measured against against the version installed, and failed on the bump to 1.48.1.
 *
 * **Three helpers, not one flipped quantifier.** PHPStan's `hasVariableType()` answers a trinary and the
 * three tails are not negations of each other: `->no()` is not `! ->yes()`, because a variable defined on
 * one branch and not another is `Maybe` for both. Mago's `VariableDefinedness` is the same three states, so
 * each tail is one identity comparison:
 *
 * | PHPStan tail | `VariableDefinedness` |
 * |:--|:--|
 * | `->yes()`    | `Defined`             |
 * | `->no()`     | `Undefined`           |
 * | `->maybe()`  | `PossiblyDefined`     |
 *
 * `DisallowedImplicitArrayCreationRule` is why all three exist rather than the two the other rules need: it
 * reports a *different message* for `no` than for `maybe` -- "does not exist" against "might not exist" --
 * so collapsing the pair would not merely widen the finding, it would mislabel it.
 *
 * **`null` and `Undefined` are different answers, and the difference is load-bearing.** The source says it
 * outright -- `return $definedness[$variable] ?? VariableDefinedness::Undefined` -- so an absent *name* in a
 * present map is `Undefined`, while `null` is the whole *map* being absent. That happens when the plugin did
 * not declare `FileAnalysisRequirement::VariableDefinedness`, and **also when the target was skipped or not
 * analysed at all**. Declaring the requirement therefore does not make `null` unreachable; an earlier draft
 * of this docblock claimed it did, on the first half of that sentence alone.
 *
 * So a null-coalesce to `Undefined` is the wrong shape, and wrong in a way that depends on which tail asks.
 * It would make a rule guarding on `->no()` report on every target mago never looked at --
 * `NoMissingVariableDimFetchRule` is exactly that rule and it emits through here. It would equally invert
 * `OverwriteVariablesWithForeachRule`, which reports when the variable *is* already defined, into reporting
 * on every fresh loop variable.
 *
 * Each helper instead compares against the one state it wants, so `null` satisfies none of them and every
 * tail stays quiet. **That is safe for all three by construction rather than by polarity** -- worth stating,
 * because the two `Overwrite*` rules would have been safe anyway by guarding on `->yes()`, and inheriting
 * that bound instead of stating it is how the `->no()` rule would have slipped through.
 *
 * **The requirement has an unmeasured cost.** The upstream change that added it touched `performance.md`,
 * and nothing here has measured what asking for it costs a real run. It is opt-in per plugin, so only rules
 * that ask pay it, but the figure is not known.
 *
 * The name may be passed with or without its leading `$`; mago normalises it either way.
 *
 * **Each helper takes a nullable name and answers false for null, rather than coercing to `''`.** The name
 * reaches these from `Support::directVariableName()`, which answers null for a variable whose name is itself
 * an expression. `getVariableDefinedness('')` throws, so the tempting `?? ''` turns a `$$dynamic` into a
 * fatal inside the analyser. False is also the honest answer for all three: a variable with no static name
 * is not certainly defined, not certainly undefined, and not certainly either -- so every tail stays quiet,
 * which is the direction a rule's own `is_string($node->name)` guard was going to take anyway.
 */
final class Definedness
{
    /** `$scope->hasVariableType($name)->yes()` — the variable certainly exists before the node runs. */
    public static function variableIsDefined(NodeAnalysisContext $context, ?string $name): bool
    {
        return $name !== null && $context->getVariableDefinedness($name) === VariableDefinedness::Defined;
    }

    /** `$scope->hasVariableType($name)->no()` — the variable certainly does not exist. */
    public static function variableIsUndefined(NodeAnalysisContext $context, ?string $name): bool
    {
        return $name !== null && $context->getVariableDefinedness($name) === VariableDefinedness::Undefined;
    }

    /** `$scope->hasVariableType($name)->maybe()` — defined on some paths reaching the node and not others. */
    public static function variableIsPossiblyDefined(NodeAnalysisContext $context, ?string $name): bool
    {
        return $name !== null && $context->getVariableDefinedness($name) === VariableDefinedness::PossiblyDefined;
    }
}
