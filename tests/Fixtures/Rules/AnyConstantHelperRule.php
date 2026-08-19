<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks a helper whether *any* declared constant matches, using a loop.
 *
 * A predicate helper is inlined as one expression, so a loop inside it cannot stay a loop. This shape —
 * `foreach (...) { if (cond) { return true; } } return false;` — is the same question `array_any()` asks, and
 * emits the same combinator.
 *
 * @implements Rule<ClassConst>
 */
final class AnyConstantHelperRule implements Rule
{
    public const string ERROR_MESSAGE = 'A constant is named id';

    public function getNodeType(): string
    {
        return ClassConst::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->declaresId($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.anyConstantHelper')
                ->build(),
        ];
    }

    private function declaresId(ClassConst $node): bool
    {
        foreach ($node->consts as $const) {
            if ($const->name->toString() === 'ID') {
                return true;
            }
        }

        return false;
    }
}
