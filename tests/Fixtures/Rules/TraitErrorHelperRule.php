<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * A shim over a helper that returns the finding rather than a boolean.
 *
 * @implements Rule<FuncCall>
 */
final class TraitErrorHelperRule implements Rule
{
    use DetectsDebugCalls;

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $error = $this->debugCallError($node->name->name);

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }
}
