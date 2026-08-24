<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Skips a method that carries any attribute, which is how `NoReturnSetterMethodRule` opens.
 *
 * php-parser nests attributes one level deeper than Mago's metadata does — groups, each holding attributes —
 * and this reads the flattened list. Exact for the question asked: a declaration has no groups exactly when it
 * has no attributes.
 *
 * The good example carries an *imported* attribute, because metadata resolves the name and the original sees
 * the resolved one too; the bad example carries none. Between them the emptiness test is exercised in both
 * directions, which is what a mapping needs before it is trusted — the two before this one each shipped a
 * defect that only a fixture caught.
 *
 * @implements Rule<ClassMethod>
 */
final class AttributedMethodRule implements Rule
{
    public const string ERROR_MESSAGE = 'Give this method an attribute';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->attrGroups !== []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.attributedMethod')
                ->build(),
        ];
    }
}
