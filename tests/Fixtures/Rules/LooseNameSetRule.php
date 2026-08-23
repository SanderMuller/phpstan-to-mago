<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A membership test written with the loose `in_array()`, which rules do write.
 *
 * Translated as the strict form only because the two provably answer the same question here: the haystack is
 * written string literals, none of them numeric, and the needle is a name. Take any of those away and the
 * transpiler refuses — {@see Transpiler::refuseLooseUnlessItAgreesWithStrict}.
 *
 * The pair under `examples/` is what makes the equivalence a measurement rather than an argument: the gate
 * runs this rule under PHPStan and the emitted plugin under mago, and compares what each reports.
 *
 * @implements Rule<MethodCall>
 */
final class LooseNameSetRule implements Rule
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

        if (! in_array($node->name->toString(), ['forbidden', 'alsoForbidden'])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.looseNameSet')
                ->build(),
        ];
    }
}
