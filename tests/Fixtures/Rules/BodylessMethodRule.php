<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `$node->stmts === null`, which asks whether a method declaration has a body.
 *
 * Three rules in the corpus open with this guard and none of them is about bodyless methods — they use it to
 * skip an abstract or interface declaration before searching the body. So the fixture inverts it and reports
 * the bodyless ones, which is what makes the guard the only thing the pair can disagree on.
 *
 * php-parser spells the absence as a null statement list. Mago gives an abstract method a `MethodAbstractBody`
 * child instead, which `Support::bodyOf()` does not count as a body, so both sides answer the same question.
 * The pair under `examples/` is where that equivalence is measured rather than argued.
 *
 * @implements Rule<ClassMethod>
 */
final class BodylessMethodRule implements Rule
{
    public const string ERROR_MESSAGE = 'This method declares no body';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->stmts !== null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.bodylessMethod')
                ->build(),
        ];
    }
}
