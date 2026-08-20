<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Two independent checks of one node, the shape a merged rule has.
 *
 * Flattened into one guard chain the first check's "not my case" exits the rule, so the second never runs.
 * The emitted plugin puts each check in its own method, and the shared prologue passes what each one names.
 * `$namespace` is that: computed once, before either check, and read by both — which is the only thing in
 * this repository that exercises passing a prologue local into a check method.
 *
 * @implements Rule<FuncCall>
 */
final class TwoChecksRule implements Rule
{
    public const string DEBUG_MESSAGE = 'No dump() in the %s namespace';

    public const string INVADE_MESSAGE = 'No invade() in the %s namespace';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $namespace = $this->matchNamespace($scope);

        if ($namespace === null) {
            return [];
        }

        $errors = [];

        $debug = $this->debugError($node, $namespace);
        if ($debug instanceof IdentifierRuleError) {
            $errors[] = $debug;
        }

        $invade = $this->invadeError($node, $namespace);
        if ($invade instanceof IdentifierRuleError) {
            $errors[] = $invade;
        }

        return $errors;
    }

    private function matchNamespace(Scope $scope): ?string
    {
        if ($this->namespaceStartsWith($scope, 'Checks\\')) {
            return 'Checks';
        }

        if ($this->namespaceStartsWith($scope, 'Probes\\')) {
            return 'Probes';
        }

        return null;
    }

    private function namespaceStartsWith(Scope $scope, string $prefix): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && str_starts_with($namespace, $prefix);
    }

    private function debugError(FuncCall $node, string $namespace): ?IdentifierRuleError
    {
        if (! $node->name instanceof Name || $node->name->toString() !== 'dump') {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(self::DEBUG_MESSAGE, $namespace))
            ->identifier('fixture.twoChecks.debug')
            ->build();
    }

    private function invadeError(FuncCall $node, string $namespace): ?IdentifierRuleError
    {
        if (! $node->name instanceof Name || $node->name->toString() !== 'invade') {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(self::INVADE_MESSAGE, $namespace))
            ->identifier('fixture.twoChecks.invade')
            ->build();
    }
}
