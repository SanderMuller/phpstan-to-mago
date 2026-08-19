<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A message that embeds a namespaced class name between double quotes.
 *
 * Both halves are here on purpose. The class constant is what `PhpBackend::bytes()` used to mangle — every
 * separator in `Doctrine\Bundle\...` disappeared, so the rule named a class that does not exist — and the
 * quotes are what the first fix for that broke, because a Rust byte string escapes them and PHP must not.
 * A snapshot of this rule fails on either mistake.
 *
 * @implements Rule<ClassConstFetch>
 */
final class QuotedClassNameMessageRule implements Rule
{
    public const string ERROR_MESSAGE = 'Constant access must go through "%s" instead';

    private const string CONTRACT = 'Acme\Contract\Repository\ServiceRepositoryInterface';

    public function getNodeType(): string
    {
        return ClassConstFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Node\Name) {
            return [];
        }

        if ($node->class->toString() !== 'static') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(self::ERROR_MESSAGE, self::CONTRACT))
                ->identifier('fixture.quotedClassNameMessage')
                ->build(),
        ];
    }
}
