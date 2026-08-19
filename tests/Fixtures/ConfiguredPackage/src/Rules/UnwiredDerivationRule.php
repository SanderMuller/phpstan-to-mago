<?php

declare(strict_types=1);

namespace Fixture\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Derives a property from a constructor value the package never wires.
 *
 * Nothing declares what the value is or defaults to, so there is nothing to derive from. That is a fact
 * about the package rather than a gap in the transpiler.
 *
 * @implements Rule<FuncCall>
 */
final class UnwiredDerivationRule implements Rule
{
    public const string ERROR_MESSAGE = 'Unwired derivation';

    /** @var array<string, true> */
    private array $lookup;

    /**
     * @param list<string> $whatever
     */
    public function __construct(private array $whatever)
    {
        $this->lookup = array_fill_keys($whatever, true);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->lookup === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.unwiredDerivation')
                ->build(),
        ];
    }
}
