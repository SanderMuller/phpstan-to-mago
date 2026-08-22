<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Folds a produced record over the receiver's classes into an accumulator.
 *
 * The shape `hihaho/phpstan-rules` v3.15.2 introduced: a record producer called inside a loop, assigned to a
 * name declared before it, and read after it. Being untranslatable is the fixture. A record's fields are
 * expressions over the item the emitted `foreach` binds, so carrying one out of the loop names a variable that
 * is out of scope there — the same escape a report anchored on a loop item makes, which this transpiler already
 * refuses.
 *
 * @implements Rule<MethodCall>
 */
final class FoldsARecordRule implements Rule
{
    public const string ERROR_MESSAGE = 'Method %s takes a bare flag';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $error = $this->foldError($node, $scope);

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }

    private function foldError(MethodCall $node, Scope $scope): ?IdentifierRuleError
    {
        $site = $this->agreedSite($node, $scope);
        if ($site === null) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(self::ERROR_MESSAGE, $site['method']))
            ->identifier('fixture.foldsARecord')
            ->build();
    }

    /**
     * @return array{method: string}|null
     */
    private function agreedSite(MethodCall $node, Scope $scope): ?array
    {
        $site = null;

        foreach (TypeCombinator::removeNull($scope->getType($node->var))->getObjectClassReflections() as $classReflection) {
            $record = $this->siteFor($classReflection->getName());
            if ($record === null) {
                return null;
            }

            $site = $record;
        }

        return $site;
    }

    /**
     * @return array{method: string}|null
     */
    private function siteFor(string $class): ?array
    {
        if ($class === '') {
            return null;
        }

        return ['method' => $class];
    }
}
