<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The attribute walk written with the opposite answer, to pin down where the polarity is carried.
 *
 * `NoEntityOutsideEntityNamespaceRule` writes the same two loops and answers `true` on a match. This one
 * answers `false` on a match and `true` at the end, which is the question negated — and the emitted plugin
 * negates with it, because the caller wraps the folded condition in the literal the guard returned rather
 * than assuming one. Snapshotted for that: a fold that dropped the literal would report exactly where this
 * rule stays silent, and nothing else in the corpus writes the walk this way.
 *
 * @implements Rule<Class_>
 */
final class InvertedAttributeWalkRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @param Class_ $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->lacksMarker($node)) {
            return [];
        }

        return [RuleErrorBuilder::message('Marked.')
            ->identifier('fixture.invertedAttributeWalk')
            ->build()];
    }

    private function lacksMarker(Class_ $class): bool
    {
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === 'Doctrine\ORM\Mapping\Entity') {
                    return false;
                }
            }
        }

        return true;
    }
}
