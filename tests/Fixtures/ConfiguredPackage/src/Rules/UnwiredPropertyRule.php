<?php

declare(strict_types=1);

namespace Fixture\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Takes a constructor value the package's neon never wires.
 *
 * Nothing declares what it is or what it defaults to, so it stays unknown. Carrying an invented default
 * would be a guess presented as configuration.
 *
 * @implements Rule<FuncCall>
 */
final class UnwiredPropertyRule implements Rule
{
    public const string ERROR_MESSAGE = 'Unwired';

    /**
     * @param list<string> $whatever
     */
    public function __construct(private array $whatever) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->whatever === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.unwired')
                ->build(),
        ];
    }
}
