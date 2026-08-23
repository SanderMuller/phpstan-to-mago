<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The loose membership test the transpiler must keep refusing: the haystack is names it canonicalises.
 *
 * Being wrong is the fixture. The plugin reads used traits from metadata, which holds them lowercased, so the
 * strict form is translated as a case-folded comparison — that is what a strict test against canonical names
 * asks for. `==` between two strings is already case-sensitive, so folding it would report where the rule
 * stays silent. It has no `examples/` pair because it never emits.
 *
 * @implements Rule<InClassNode>
 */
final class LooseTraitNameSetRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not use this trait';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();

        if (! in_array('Forbidden', $classReflection->getTraits(true))) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.looseTraitNameSet')
                ->build(),
        ];
    }
}
