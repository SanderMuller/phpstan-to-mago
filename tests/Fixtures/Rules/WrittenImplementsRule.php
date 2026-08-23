<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gates on `$classLike->implements`, the interfaces a declaration *writes*.
 *
 * The shape two `symplify` listener rules use, and the reason the mapping behind it needs a fixture: Mago's
 * metadata carries both `$directParentInterfaces` and `$parentInterfaces`, and they differ on exactly the case
 * this rule turns on. A class implementing nothing itself but extending one that implements an interface has an
 * empty `implements` clause, so PHPStan reports it — and reading the transitive list instead would go silent
 * there. The example pair holds that class, so the wrong list fails the gate rather than passing quietly.
 *
 * @implements Rule<InClassNode>
 */
final class WrittenImplementsRule implements Rule
{
    public const string ERROR_MESSAGE = 'Implement an interface on this class';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classLike = $node->getOriginalNode();
        if (! $classLike instanceof Class_) {
            return [];
        }

        if ($classLike->implements !== []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.writtenImplements')
                ->build(),
        ];
    }
}
