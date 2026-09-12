<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Two attribute-name guards that answer differently, which cannot fold to one question.
 *
 * The fold reads the walk as one condition and lets the caller wrap it in the single literal the rule
 * returned. That holds while every guard answers the same way. Here one answers `true` and the other
 * `false`, so no single literal is right for both names — folding them into a disjunction would invert the
 * rule for whichever name lost. Refused instead, and no rule in the corpus writes this, which is why the
 * fixture exists.
 *
 * @implements Rule<Class_>
 */
final class DisagreeingAttributeWalkRule implements Rule
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
        if ($this->answersBothWays($node)) {
            return [];
        }

        return [RuleErrorBuilder::message('Marked.')
            ->identifier('fixture.disagreeingAttributeWalk')
            ->build()];
    }

    private function answersBothWays(Class_ $class): bool
    {
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === 'Doctrine\ORM\Mapping\Entity') {
                    return true;
                }

                if ($attr->name->toString() === 'Doctrine\ORM\Mapping\Embeddable') {
                    return false;
                }
            }
        }

        return false;
    }
}
