<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reads `$node->namespacedName` on a class declaration, the shape two `symplify` rules use.
 *
 * Two things need a fixture, and neither is the property itself. The name has to keep its **case**, because
 * both corpus rules do a case-sensitive `str_ends_with` on it — metadata lowercases every name it holds, and
 * this reads off the CST instead, which is why it survives.
 *
 * And the `instanceof Name` guard has to be *folded*, not translated. PHPStan leaves that property null only
 * for an anonymous class, and a class-like hook never receives one: Mago gives anonymous classes their own node
 * kind. Translating the test instead emitted `Support::isName()` on a name *string*, whose parameter is a
 * `Part` — a TypeError the first time the rule ran. The good example holds an anonymous class so that the fold
 * is exercised rather than merely reasoned about.
 *
 * @implements Rule<Class_>
 */
final class QualifiedDeclarationNameRule implements Rule
{
    public const string ERROR_MESSAGE = 'Name this class with a FormType suffix';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->namespacedName instanceof Name) {
            return [];
        }

        if (str_ends_with($node->namespacedName->toString(), 'FormType')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.qualifiedDeclarationName')
                ->build(),
        ];
    }
}
