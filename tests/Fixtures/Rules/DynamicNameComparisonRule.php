<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule reading a property whose *name* is computed, in a comparison the vocabulary does cover.
 *
 * `$node->name` is `Identifier|Expr` on most of php-parser's nodes, and sixteen places in the translator
 * used to compare it with `(string) $node->name`. An `Identifier` has `__toString()` and an `Expr` does not,
 * so this fixture used to kill the transpiler inside the cast and surface as
 *
 *     REFUSE  Object of class PhpParser\Node\Scalar\String_ could not be converted to string
 *
 * which names a PHP type error instead of the construct. Being invalid is the whole fixture: no rule in the
 * corpus writes a computed property name, so nothing there reaches those casts.
 *
 * @implements Rule<Node\Stmt\ClassConst>
 */
final class DynamicNameComparisonRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt\ClassConst::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->{'value'} > 3) {
            return [RuleErrorBuilder::message('too big')->identifier('fixture.tooBig')->build()];
        }

        return [];
    }
}
