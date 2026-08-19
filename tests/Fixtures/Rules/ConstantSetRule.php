<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks whether a name is in a set written as a constant map.
 *
 * `['dump' => true, ...]` is how the corpus spells a set, and `isset(self::X[$name])` is how it asks. The
 * values carry nothing; the keys are the set.
 *
 * @implements Rule<FuncCall>
 */
final class ConstantSetRule implements Rule
{
    public const string ERROR_MESSAGE = 'No debug calls';

    /** @var array<string, true> */
    private const array DEBUG_FUNCTIONS = [
        'dump' => true,
        'dd' => true,
        'var_dump' => true,
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if (! isset(self::DEBUG_FUNCTIONS[$node->name->name])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.constantSet')
                ->build(),
        ];
    }
}
