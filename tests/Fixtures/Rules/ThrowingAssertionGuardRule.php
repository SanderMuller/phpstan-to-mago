<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;

/**
 * A guard whose body throws: an assertion about the dispatch, not a decision the rule makes.
 *
 * `throw` is an expression in PHP 8, so php-parser wraps it in a `Stmt_Expression` — which is what the
 * refusal used to name, one wrapper away from the thing that mattered.
 *
 * It translates to the exit `return []` takes. On every input where the assertion holds, and the author
 * writing `ShouldNotHappenException` is asserting that is every input, the two engines agree; where it does
 * not hold PHPStan raises and the plugin declines, which is the under-reporting direction.
 *
 * In this snapshot the exit does not survive: the hook fires only on a class-like or its members, so the
 * condition is provably false and the existing impossible-guard drop consumes it. That composition is the
 * point — the branch's job is to hand `translateGuard` an exit, and what happens next is not its business.
 * Both corpus instances of this shape guard on the same condition, so this is the emission they would get.
 *
 * The name test is written `$node->name->name`, which is how the corpus spells it — `RequireParentConstructCallRule`
 * among them. It emitted `directVariableName(declarationName(..))` until the receiver's kind was checked,
 * passing a string where a Node is expected; the plugin parsed, so only the emitted-plugin type check caught
 * it. Both spellings now produce the same call, which is why this snapshot did not move when it was fixed.
 *
 * @implements Rule<ClassMethod>
 */
final class ThrowingAssertionGuardRule implements Rule
{
    public const string ERROR_MESSAGE = 'A method named handle belongs on a handler';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $scope->isInClass()) {
            throw new ShouldNotHappenException();
        }

        if ($node->name->name !== 'handle') {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.throwingAssertionGuard')
                ->build(),
        ];
    }
}
