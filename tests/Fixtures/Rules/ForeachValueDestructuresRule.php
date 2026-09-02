<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `$node->valueVar`, which is the one child every foreach has and two kinds hold at different positions.
 *
 * The other half of what {@see ForeachKeyOverwritesRule} measures, and the reason both are needed: the value
 * is the *only* expression under a `ForeachValueTarget` and the *second* under a `ForeachKeyValueTarget`, so
 * a helper that read a fixed position would answer the key for every keyed loop and no test on the key alone
 * would notice. This rule reports a destructuring target, which is a value that is not a plain variable — so
 * a port reading the wrong child reports the keyed loops instead and the pair fails.
 *
 * @implements Rule<Foreach_>
 */
final class ForeachValueDestructuresRule implements Rule
{
    public const string ERROR_MESSAGE = 'This foreach destructures its value';

    public function getNodeType(): string
    {
        return Foreach_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->valueVar instanceof Variable) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.foreachValueDestructures')
                ->build(),
        ];
    }
}
