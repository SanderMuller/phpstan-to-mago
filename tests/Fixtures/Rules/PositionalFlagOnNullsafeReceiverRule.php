<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * A bare bool or null flag passed positionally as the last argument of a nullsafe method call.
 *
 * The same detection as {@see PositionalFlagOnReceiverRule} on the other hook. `$obj?->m(..)` is a node kind of
 * its own in Mago — probed, not assumed — so it needs its own hook and its own gate run, and the `MethodCall`
 * hook does not fire for it.
 *
 * A nullsafe receiver is nullable by definition, so `TypeCombinator::removeNull()` is not optional here: every
 * call this rule sees carries a null atomic beside the object one. Measured by mutation, not reasoned about —
 * swap `Support::soleObjectClassIgnoringNull()` for the strict `soleObjectClass()` in the emitted plugin and this
 * rule reports **nothing at all**, while the file still parses, loads and calls only helpers that exist. That is
 * why the two helpers are separate and must stay separate.
 *
 * @implements Rule<NullsafeMethodCall>
 */
final class PositionalFlagOnNullsafeReceiverRule implements Rule
{
    private const array FIRST_PARTY = ['Examples'];

    public function getNodeType(): string
    {
        return NullsafeMethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $error = $this->flagErrorFromSite($this->flagSiteForNullsafeMethodCall($node, $scope));

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }

    /** @return array{method: string, argIndex: int, paramName: string}|null */
    private function flagSiteForNullsafeMethodCall(NullsafeMethodCall $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        return $this->instanceCallFlagSite($node->var, $node->name->name, $node->getArgs(), $scope);
    }

    /**
     * @param  array<Arg>  $args
     * @return array{method: string, argIndex: int, paramName: string}|null
     */
    private function instanceCallFlagSite(Expr $receiver, string $methodName, array $args, Scope $scope): ?array
    {
        $flagIndex = $this->lastBareFlagIndex($args);

        if ($flagIndex === null) {
            return null;
        }

        $classReflections = TypeCombinator::removeNull($scope->getType($receiver))->getObjectClassReflections();

        if (count($classReflections) !== 1 || ! $classReflections[0]->hasMethod($methodName)) {
            return null;
        }

        return $this->flagRecord($classReflections[0]->getMethod($methodName, $scope), $methodName, $args, $flagIndex);
    }

    /**
     * @param  array<Arg>  $args
     * @return array{method: string, argIndex: int, paramName: string}|null
     */
    private function flagRecord(ExtendedMethodReflection $method, string $methodLabel, array $args, int $flagIndex): ?array
    {
        if (! $this->isFirstPartyClass($method->getDeclaringClass()->getName())) {
            return null;
        }

        $parameters = $method->getVariants()[0]->getParameters();
        $parameter = $parameters[$flagIndex] ?? null;

        if ($parameter === null || $parameter->isVariadic()) {
            return null;
        }

        return [
            'method' => $methodLabel,
            'argIndex' => $flagIndex,
            'paramName' => $parameter->getName(),
        ];
    }

    /** @param  array{method: string, argIndex: int, paramName: string}|null  $site */
    private function flagErrorFromSite(?array $site): ?IdentifierRuleError
    {
        return $site === null ? null : $this->flagError($site['paramName']);
    }

    private function flagError(string $paramName): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Pass a named argument (%s: ...) for the bool/null flag — it is opaque positionally.',
            $paramName,
        ))
            ->identifier('fixture.positionalFlagOnNullsafeReceiver')
            ->build();
    }

    /** @param  array<Arg>  $args */
    private function lastBareFlagIndex(array $args): ?int
    {
        if ($args === []) {
            return null;
        }

        $lastIndex = count($args) - 1;

        if (! $this->isBareBoolOrNullFlag($args[$lastIndex])) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg->name instanceof Identifier || $arg->unpack) {
                return null;
            }
        }

        return $lastIndex;
    }

    private function isBareBoolOrNullFlag(Arg $arg): bool
    {
        if ($arg->name instanceof Identifier || $arg->unpack) {
            return false;
        }

        if (! $arg->value instanceof ConstFetch) {
            return false;
        }

        return match ($arg->value->name->toLowerString()) {
            'true', 'false', 'null' => true,
            default => false,
        };
    }

    private function isFirstPartyClass(string $className): bool
    {
        foreach (self::FIRST_PARTY as $namespace) {
            if (str_starts_with($className, rtrim($namespace, '\\') . '\\')) {
                return true;
            }
        }

        return false;
    }
}
