<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule that anchors a finding on a loop item and then returns it from outside the loop.
 *
 * Invalid on purpose, like `MissingHelperRule`. `->line($classMethod->getLine())` moves the finding onto the member
 * the rule is talking about, and the emitted anchor is the PHP variable the generated `foreach` binds — so a report
 * emitted *after* that loop names a variable that is no longer there. The span would be wrong and nothing static
 * would see it: the plugin parses, loads, and reports on whatever PHP leaves in that variable.
 *
 * Every real rule in the corpus reports inside the loop that anchored it, so nothing exercises the guard against
 * this. That is what this fixture is for.
 *
 * @implements Rule<Class_>
 *
 * @phpstan-ignore-next-line the shape is the fixture
 */
final class AnchorEscapesLoopRule implements Rule
{
    private const string ERROR_MESSAGE = 'This method should not be here';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->getMethods() as $classMethod) {
            if (! $classMethod->isPublic()) {
                continue;
            }

            $error = RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.anchorEscapesLoop')
                ->line($classMethod->getLine())
                ->build();
        }

        return [$error];
    }
}
