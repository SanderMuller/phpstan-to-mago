<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A bare bool or null flag passed positionally as a constructor's last argument.
 *
 * The shape `hihaho/phpstan-rules` factors its positional-flag family into, kept spelling for spelling so the
 * gate runs what the real rule runs. Four things here have no counterpart in the rest of the corpus:
 *
 * - A **record producer**. `flagSiteForNew()` hands back a `{method, argIndex, paramName, value}` array and
 *   `flagErrorFromSite()` reads one field out of it, so one implementation can drive both this rule and a
 *   manifest collector. No runtime array survives translation: the producer's guards become the rule's guards
 *   and each key becomes a transpile-time binding.
 * - A **value producer**. `lastBareFlagIndex()` guards three times and then returns `count($args) - 1`, which
 *   is neither a finding nor a case name.
 * - **Reflection on a class named at analysis time**: `$scope->resolveName()`, then `hasClass()`,
 *   `hasConstructor()` and the constructor's parameter at a position.
 * - The **variants ternary**. PHPStan models a function-like as one or more variants; Mago's metadata carries
 *   exactly one parameter list, so the single-variant branch is the only one that has an equivalent.
 *
 * The one deliberate difference from the real rule: the first-party namespaces are a class constant here rather
 * than an injected configured list, because the gate registers a rule with no constructor arguments.
 * Configuration delivery has its own tests.
 *
 * @implements Rule<New_>
 */
final class PositionalFlagRule implements Rule
{
    private const array FIRST_PARTY = ['Examples'];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return New_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $error = $this->flagErrorFromSite($this->flagSiteForNew($node, $scope, $this->reflectionProvider));

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }

    /** @return array{method: string, argIndex: int, paramName: string}|null */
    private function flagSiteForNew(New_ $node, Scope $scope, ReflectionProvider $reflectionProvider): ?array
    {
        if (! $node->class instanceof Name) {
            return null;
        }

        $args = $node->getArgs();
        $flagIndex = $this->lastBareFlagIndex($args);

        if ($flagIndex === null) {
            return null;
        }

        $className = $scope->resolveName($node->class);

        if (! $reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $reflectionProvider->getClass($className);

        if (! $classReflection->hasConstructor()) {
            return null;
        }

        return $this->flagRecord($classReflection->getConstructor(), $className, $args, $flagIndex, $scope);
    }

    /**
     * @param  array<Arg>  $args
     * @return array{method: string, argIndex: int, paramName: string}|null
     */
    private function flagRecord(ExtendedMethodReflection $method, string $methodLabel, array $args, int $flagIndex, Scope $scope): ?array
    {
        if (! $this->isFirstPartyClass($method->getDeclaringClass()->getName())) {
            return null;
        }

        $variants = $method->getVariants();

        $parameters = (count($variants) === 1
            ? $variants[0]
            : ParametersAcceptorSelector::selectFromArgs($scope, $args, $variants)
        )->getParameters();
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
            ->identifier('fixture.positionalFlagArgument')
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
