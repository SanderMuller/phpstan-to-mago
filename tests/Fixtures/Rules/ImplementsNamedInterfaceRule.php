<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Sandermuller\PhpstanToMago\Tests\Fixtures\Rules\Enum\FixtureInterfaceName;

/**
 * `implementsInterface()` asked with a written name, and with a constant standing for one.
 *
 * Both spellings used to be refused. The argument went through generic expression resolution, which refuses
 * a string literal by node kind on purpose, so only a name read off the analysed file worked — and the
 * corpus rule that reaches here writes `implementsInterface(SymfonyClass::EVENT_SUBSCRIBER_INTERFACE)`.
 *
 * Two guards rather than one, because the two spellings take different routes to the same text and a pair
 * that exercised only the literal would leave the constant unproven.
 *
 * @implements Rule<InClassNode>
 */
final class ImplementsNamedInterfaceRule implements Rule
{
    public const string ERROR_MESSAGE = 'This class implements both fixture interfaces';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();
        if (! $classReflection instanceof ClassReflection) {
            return [];
        }

        if (! $classReflection->implementsInterface('Examples\Stubs\NamedByLiteral')) {
            return [];
        }

        if (! $classReflection->implementsInterface(FixtureInterfaceName::NAMED)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.implementsNamedInterface')
                ->build(),
        ];
    }
}
