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
 * A cache declared part-way through a helper, rather than wrapping the whole of it.
 *
 * `hihaho/phpstan-rules` writes one of these in `DetectsFacadeAlias`: guards first, then `static $cache = []`,
 * then a keyed fill, then a read. The whole-helper form is recognised elsewhere; this one sits between other
 * statements, so only the reads can say what it stood for.
 *
 * Nothing is emitted for the declaration or the fill. The read resolves to the question the cache memoised, so
 * the emitted plugin asks that question directly — which is sound because a cache cannot change an answer.
 *
 * @implements Rule<FuncCall>
 */
final class MidBodyCacheRule implements Rule
{
    public const string ERROR_MESSAGE = 'No dump() in a namespaced file';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if (! $this->isReportableDebugCall($node->name->toString(), $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.midBodyCache')
                ->build(),
        ];
    }

    private function isReportableDebugCall(string $functionName, Scope $scope): bool
    {
        if ($scope->getNamespace() === null) {
            return false;
        }

        /**
         * Keyed on the only thing the answer depends on. That is what makes dropping the cache sound: a
         * per-process cache outlives the file, so a key that does *not* determine its value changes the result
         * — the first version of this fixture cached the enclosing namespace under the function name, and
         * PHPStan then reported a file with no namespace at all, having cached one from the file before it.
         *
         * @var array<string, string> $cache
         */
        static $cache = [];

        if (! array_key_exists($functionName, $cache)) {
            $cache[$functionName] = strtolower($functionName);
        }

        return $cache[$functionName] === 'dump';
    }
}
