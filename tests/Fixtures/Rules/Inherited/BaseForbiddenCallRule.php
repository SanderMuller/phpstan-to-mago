<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules\Inherited;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A base that declares both of a rule's required methods, so its subclasses declare neither.
 *
 * `phpat/phpat` writes 57 of its 59 rules this way — a two-line class extending a base and implementing
 * `Rule` — and the transpiler read `getNodeType()` and `processNode()` off the rule's own class alone. Every
 * one refused as though it had no node type at all, which is a refusal naming the wrong thing about a rule
 * that has one.
 */
abstract class BaseForbiddenCallRule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if ($node->name->toString() !== 'forbidden') {
            return [];
        }

        return [
            RuleErrorBuilder::message('Do not call this method')
                ->identifier('fixture.inheritedRuleMethods')
                ->build(),
        ];
    }
}
