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
 * Asks membership in a table the constructor built, rather than in a constant.
 *
 * `isset(self::CONST[$k])` is a set known while transpiling; `isset($this->built[$k])` is an array read at
 * analysis time, because the table is a property the generated plugin carries. The derivation is copied
 * verbatim, so the two questions look the same in the rule and different in the output.
 *
 * @implements Rule<FuncCall>
 */
final class ConstructedLookupRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not call a debug helper';

    /** @var array<string, true> */
    private array $built;

    public function __construct()
    {
        // Literals only. A class constant would be refused: the generated plugin carries no constants, so
        // copying a derivation that reads one emits a reference to nothing.
        $this->built = array_fill_keys(['dump', 'dd'], true);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if (! isset($this->built[$node->name->name])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.constructedLookup')
                ->build(),
        ];
    }
}
