<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asks PHPStan for a node type this vocabulary maps to no hook, and then needs something of the body too.
 *
 * Both halves matter. A rule with no hook cannot become a plugin whatever its body says, so an emit run
 * refuses on the hook; a survey run assumes the hook on purpose, to report what the body would need as well.
 * The fixture exists to pin that the survey says which of those it did — a body-level gap reported without
 * the assumption behind it reads as the only thing in the way, and closing it would move nothing.
 *
 * @implements Rule<ConstFetch>
 */
final class UnmappedNodeTypeRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not fetch that constant';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return ConstFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->reflectionProvider->hasConstant($node->name, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.unmappedNodeType')
                ->build(),
        ];
    }
}
