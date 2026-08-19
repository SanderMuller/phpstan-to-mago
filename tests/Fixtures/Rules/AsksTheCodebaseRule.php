<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks whether a class is known, which PHPStan answers through `ReflectionProvider`.
 *
 * The service itself is never handed to a generated plugin — no worker can supply one — but the *question*
 * translates: Mago answers it from the codebase it scanned.
 *
 * @implements Rule<FuncCall>
 */
final class AsksTheCodebaseRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not call helper() when the Facade exists';

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if ($node->name->toString() !== 'helper') {
            return [];
        }

        // A class the examples themselves declare, so both tools can answer from what they scanned rather
        // than from an autoloader neither of them has.
        if (! $this->reflectionProvider->hasClass('Examples\Helpers\Behaved')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.asksTheCodebase')
                ->build(),
        ];
    }
}
