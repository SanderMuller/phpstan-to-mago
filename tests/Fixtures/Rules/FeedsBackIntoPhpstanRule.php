<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * A rule whose whole output is a node it hands back to PHPStan, which is what `DataProviderDataRule` does.
 *
 * Being unportable is the fixture. It returns `[]` on every path and calls `$scope->invokeNodeCallback()` with
 * a call it builds itself, so PHPStan's *other* rules report on something the source does not contain. An
 * analyzer plugin's only output is `report()`, and nothing in Mago receives a synthesised node — so no hook
 * and no vocabulary entry can help, which is why the refusal has to come before the body is read.
 *
 * @implements Rule<MethodCall>
 */
final class FeedsBackIntoPhpstanRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $scope->invokeNodeCallback(new MethodCall($node->var, 'somethingElse'));

        return [];
    }
}
