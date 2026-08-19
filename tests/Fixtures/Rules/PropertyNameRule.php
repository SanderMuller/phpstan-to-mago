<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks whether a property declaration declares a particular name.
 *
 * `protected $a = 1, $b = 2;` is one declaration with two names, which php-parser calls `$node->props` and
 * Mago spells as `PropertyItem` children of a `PlainProperty`. The tree was read from a probe rather than
 * assumed, which is what `.ai/docs/architecture.md` asks for.
 *
 * @implements Rule<Property>
 */
final class PropertyNameRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not declare $with globally';

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->declaresWith($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.propertyName')
                ->build(),
        ];
    }

    private function declaresWith(Property $node): bool
    {
        foreach ($node->props as $prop) {
            if ($prop->name->toString() === 'with') {
                return true;
            }
        }

        return false;
    }
}
