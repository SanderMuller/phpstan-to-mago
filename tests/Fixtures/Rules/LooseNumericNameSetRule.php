<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Sandermuller\PhpstanToMago\Tests\Unit\TranspilesToPhpTest;

/**
 * The same loose membership test, with one numeric string in the haystack.
 *
 * Being wrong is the fixture. Since PHP 8 two numeric strings compare numerically, so `'0' == '0.0'` holds
 * where `===` does not — and a method named `0` cannot exist, which is exactly why nothing else in the corpus
 * would ever catch the difference. The transpiler refuses this rather than translating the loose test as the
 * strict one, and that refusal is what {@see TranspilesToPhpTest}
 * pins. It has no `examples/` pair because it never emits.
 *
 * @implements Rule<MethodCall>
 */
final class LooseNumericNameSetRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not call this method';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (! in_array($node->name->toString(), ['forbidden', '0'])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.looseNumericNameSet')
                ->build(),
        ];
    }
}
