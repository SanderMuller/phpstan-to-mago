<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gates a function call on the namespace the file declares.
 *
 * The prefix is written with its trailing separator on purpose: `App\` must not match `Application`, which
 * is the case `ChecksNamespace::scopeNamespaceMatchesPrefix()` guards the same way.
 *
 * @implements Rule<FuncCall>
 */
final class NamespacePrefixRule implements Rule
{
    public const string ERROR_MESSAGE = 'No dump() inside the App namespace';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if ($node->name->toString() !== 'dump') {
            return [];
        }

        $namespace = $scope->getNamespace();
        if ($namespace === null) {
            return [];
        }

        if (! str_starts_with($namespace, 'App\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.namespacePrefix')
                ->build(),
        ];
    }
}
